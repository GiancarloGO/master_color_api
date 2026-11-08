<?php

namespace App\Services\Chatbot;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Línea base convencional para la comparación de la evaluación: chatbot por
 * reglas, sin IA. Coincidencia exacta de palabras contra el nombre del producto
 * (sin tolerancia a errores ni sinónimos) y respuesta de plantilla.
 */
class KeywordBaseline
{
    public function reply(string $message): string
    {
        $words = array_filter(preg_split('/[^a-z0-9]+/', Str::lower($message)) ?: [], fn($w) => strlen($w) >= 3);
        $phone = config('chatbot.contact_phone');

        $hits = Product::with('stock')->whereNull('deleted_at')->whereHas('stock')->get()
            ->filter(function ($p) use ($words) {
                $name = Str::lower($p->name);
                foreach ($words as $w) {
                    if (str_contains($name, $w)) return true;
                }
                return false;
            })->take(3);

        if ($hits->isEmpty()) {
            return "No encontré coincidencias. Escríbenos al {$phone}.";
        }

        return $hits->map(fn($p) => "{$p->name}: S/ " . number_format((float) $p->stock->sale_price, 2, '.', ',') . ", stock {$p->stock->quantity}")->implode("\n");
    }
}
