<?php

namespace App\Services\Chatbot;

/**
 * Respuestas construidas 100 % por el backend a partir de datos verificados.
 * Se usan cuando el modelo no es fiable (cifras inventadas) o no está disponible.
 */
class ReplyComposer
{
    public function compose(string $status, array $items): string
    {
        $phone = config('chatbot.contact_phone');

        return match ($status) {
            'found' => "Esto es lo que encontré:\n" . $this->lines($items) . $this->staleNote($items)
                . "\n¿Te ayudo con algo más sobre estos equipos?",
            'ambiguous' => "Encontré varias opciones parecidas:\n" . $this->lines($items) . $this->staleNote($items)
                . "\n¿Cuál de ellas te interesa? Si me dices el modelo exacto, te confirmo los datos.",
            'partial' => "Esto es lo que encontré:\n" . $this->lines($items) . $this->staleNote($items)
                . "\nAlgún producto que mencionas no está en nuestro catálogo; un asesor puede ayudarte: WhatsApp {$phone}.",
            'insufficient' => '¿Qué producto buscas? Dime la marca, el modelo o el tipo (impresora, tóner, tinta) y te doy precio y disponibilidad.',
            default => "No encontré ese producto en nuestro catálogo. Un asesor puede ayudarte a ubicarlo: escríbenos por WhatsApp al {$phone}.",
        };
    }

    private function lines(array $items): string
    {
        return implode("\n", array_map(
            fn($i) => "- {$i['nombre']} ({$i['marca']}): {$i['precio_texto']} — {$i['disponibilidad']}"
                . ($i['stock'] > 0 ? " ({$i['stock']} u.)" : ''),
            $items
        ));
    }

    private function staleNote(array $items): string
    {
        foreach ($items as $i) {
            if ($i['dato_antiguo']) {
                return "\nAlgunos datos no se actualizan hace tiempo; confirma con un asesor al " . config('chatbot.contact_phone') . '.';
            }
        }

        return '';
    }
}
