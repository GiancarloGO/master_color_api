<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Chatbot\KeywordBaseline;
use App\Services\Chatbot\ToolChatbotService;
use Illuminate\Console\Command;

class ChatbotEvaluate extends Command
{
    protected $signature = 'chatbot:evaluate {--cases=tests/chatbot/cases.json} {--out=storage/app/chatbot-eval.json}';
    protected $description = 'Ejecuta las consultas de prueba contra el chatbot con IA y la línea base por reglas; reporta exactitud y tiempo.';

    public function handle(ToolChatbotService $ai, KeywordBaseline $baseline): int
    {
        $cases = json_decode(file_get_contents(base_path($this->option('cases'))), true);
        $phone = (string) config('chatbot.contact_phone');
        $rows = [];

        foreach ($cases as $case) {
            $t = microtime(true);
            try {
                $aiReply = $ai->respond([], $case['q'])['reply'];
            } catch (\Throwable $e) {
                $aiReply = 'ERROR: ' . $e->getMessage();
            }
            $aiMs = (microtime(true) - $t) * 1000;

            $t = microtime(true);
            $baseReply = $baseline->reply($case['q']);
            $baseMs = (microtime(true) - $t) * 1000;

            $rows[] = [
                'id' => $case['id'], 'type' => $case['type'], 'q' => $case['q'],
                'ai' => ['reply' => $aiReply, 'ok' => $this->grade($case, $aiReply, $phone), 'ms' => round($aiMs)],
                'baseline' => ['reply' => $baseReply, 'ok' => $this->grade($case, $baseReply, $phone), 'ms' => round($baseMs)],
            ];
        }

        $this->table(['#', 'Tipo', 'IA', 'ms', 'Base', 'Consulta'], array_map(fn($r) => [
            $r['id'], $r['type'], $r['ai']['ok'] ? 'OK' : 'FALLA', $r['ai']['ms'],
            $r['baseline']['ok'] ? 'OK' : 'FALLA', mb_strimwidth($r['q'], 0, 55, '…'),
        ], $rows));

        $n = count($rows);
        foreach (['ai' => 'IA', 'baseline' => 'Línea base'] as $key => $label) {
            $ok = count(array_filter($rows, fn($r) => $r[$key]['ok']));
            $avg = array_sum(array_column(array_column($rows, $key), 'ms')) / $n / 1000;
            $this->info(sprintf('%s: %d/%d correctas (%.1f%%), tiempo medio %.2f s', $label, $ok, $n, 100 * $ok / $n, $avg));
        }
        $this->line('Umbral de aceptación: ≥ ' . (int) ceil(0.85 * $n) . "/{$n} correctas y tiempo medio ≤ 10 s.");

        file_put_contents(base_path($this->option('out')), json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /**
     * Criterios de validez (fijados antes de la corrida con el set definitivo):
     * - product: nombra cada producto esperado con su precio vigente de la BD.
     * - clarify: pide precisar/aclarar (pregunta o solicitud explícita) o deriva al asesor.
     *   Puede listar opciones con precios reales (la fidelidad de cifras la garantiza ReplyGuard).
     * - none: no afirma ningún precio y declina o deriva al asesor.
     */
    private function grade(array $case, string $reply, string $phone): bool
    {
        $lower = mb_strtolower($reply);

        if ($case['kind'] === 'product') {
            foreach ($case['products'] as $name) {
                $p = Product::with('stock')->where('name', $name)->first();
                if (! $p) return false;
                $price = number_format((float) $p->stock->sale_price, 2, '.', ',');
                if (! str_contains($reply, $price) && ! str_contains($reply, (string) (int) $p->stock->sale_price)) return false;
            }
            return true;
        }

        $derives = $this->containsAny($lower, [$phone, 'asesor', 'whatsapp']);

        if ($case['kind'] === 'clarify') {
            return $derives || str_contains($reply, '?')
                || $this->containsAny($lower, ['indica', 'dime', 'dinos', 'precis', 'especific', 'qué producto', 'que producto', 'cuál', 'cual de']);
        }

        $mentionsPrice = (bool) preg_match('/s\/\s?\d/u', $lower);

        return ! $mentionsPrice && ($derives || $this->containsAny($lower, [
            'no tenemos', 'no vendemos', 'no encontr', 'no contamos', 'no manejamos', 'especializ', 'solo puedo', 'únicamente', 'no está en',
        ]));
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, mb_strtolower($n))) return true;
        }
        return false;
    }
}
