<?php

namespace App\Services\Chatbot;

/**
 * Verifica que toda cifra de la respuesta del modelo provenga de los datos
 * devueltos por la herramienta. Si el modelo inventa o altera un precio o una
 * cantidad, la respuesta se descarta y el backend compone una propia.
 */
class ReplyGuard
{
    /**
     * @param  array<int, array<string, mixed>>  $items  ítems devueltos por ProductSearchTool
     */
    public function isFaithful(string $reply, array $items): bool
    {
        $allowed = [];
        foreach ($items as $item) {
            $allowed[] = (float) $item['precio'];
            $allowed[] = (float) $item['stock'];
            // Las cifras de las características (ej. "35 ppm") también vienen de la BD.
            preg_match_all('/\d[\d.,]*/u', (string) ($item['descripcion'] ?? ''), $m);
            foreach ($m[0] as $raw) {
                $v = $this->toFloat($raw);
                if ($v !== null) $allowed[] = $v;
            }
        }
        // Teléfono de contacto y cantidad de opciones listadas no cuentan como dato comercial.
        foreach (preg_split('/\D+/', (string) config('chatbot.contact_phone'), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $allowed[] = (float) $part;
        }
        if ($items !== []) {
            $allowed[] = (float) count($items);
        }

        // Números sueltos; los pegados a letras (c5540, m3655) son modelos, no cifras.
        preg_match_all('/(?<![\p{L}\d])\d[\d.,]*(?![\p{L}])/u', $reply, $matches);

        foreach ($matches[0] as $raw) {
            $value = $this->toFloat($raw);
            if ($value === null) continue;

            $ok = false;
            foreach ($allowed as $a) {
                if (abs($a - $value) < 0.005) { $ok = true; break; }
            }
            if (! $ok) return false;
        }

        return true;
    }

    private function toFloat(string $raw): ?float
    {
        $raw = rtrim($raw, '.,');
        if ($raw === '') return null;

        // "3,500.00" / "3.500,00" / "3,500" / "3500"
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $raw)) return (float) str_replace(',', '', $raw);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) return (float) str_replace(['.', ','], ['', '.'], $raw);

        return is_numeric(str_replace(',', '.', $raw)) ? (float) str_replace(',', '.', $raw) : null;
    }
}
