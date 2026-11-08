<?php

namespace App\Services\Chatbot;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Única vía por la que el modelo accede a datos: búsqueda de solo lectura y
 * parametrizada. El modelo nunca escribe SQL; solo pasa argumentos que aquí se
 * validan. Precio y stock salen de la BD en cada llamada (sin caché).
 */
class ProductSearchTool
{
    public const NAME = 'buscar_productos';

    private const STOPWORDS = [
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del', 'para', 'por', 'con', 'sin', 'en', 'y', 'o',
        'que', 'qe', 'cual', 'cuales', 'cuanto', 'cuesta', 'cuestan', 'precio', 'precios', 'vale', 'valor', 'tiene',
        'tienen', 'hay', 'tienes', 'tengan', 'tenga', 'quiero', 'busco', 'necesito', 'me', 'mi', 'se', 'su', 'sus',
        'a', 'al', 'es', 'son', 'estan', 'esta', 'disponible', 'disponibles', 'stock', 'unidades', 'modelo', 'equipo',
        'hola', 'buenas', 'buenos', 'dias', 'tardes', 'noches', 'favor', 'por', 'pueden', 'puedes', 'dime', 'dame',
        'informacion', 'info', 'sobre', 'tiendas', 'venden', 'vende', 'ofrecen', 'si', 'no', 'mas', 'menos', 'barato',
        'barata', 'caro', 'cara', 'soles', 'sol', 's',
    ];

    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Busca productos en el catálogo real de Master Color y devuelve precio y stock vigentes. '
                    . 'Úsala SIEMPRE antes de mencionar un producto, precio o disponibilidad.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'consulta' => ['type' => 'string', 'description' => 'Producto buscado: marca, modelo o tipo (ej. "kyocera m3655", "toner ricoh").'],
                        'consultas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Úsalo cuando el cliente nombra VARIOS productos (comparar, "X y Y"): un texto por producto, máximo 4. Con un solo producto usa `consulta`.'],
                        'categoria' => ['type' => 'string', 'enum' => ['impresoras', 'tintas', 'toners'], 'description' => 'Categoría, si el cliente la indica.'],
                        'precio_max' => ['type' => 'number', 'description' => 'Presupuesto máximo en soles.'],
                        'precio_min' => ['type' => 'number', 'description' => 'Precio mínimo en soles.'],
                        'solo_con_stock' => ['type' => 'boolean', 'description' => 'true si solo interesa lo disponible.'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args  argumentos crudos del modelo (no confiables)
     * @return array{status: string, items: array<int, array<string, mixed>>}
     *         status: found | ambiguous | none | insufficient
     */
    public function search(array $args): array
    {
        $queries = array_values(array_filter(array_map(
            fn($q) => is_string($q) ? trim($q) : '',
            array_slice((array) ($args['consultas'] ?? []), 0, 4)
        )));

        if (count($queries) < 2) {
            return $this->searchOne($queries !== [] && empty($args['consulta']) ? $args + ['consulta' => $queries[0]] : $args);
        }

        $groups = [];
        $items = [];
        foreach ($queries as $q) {
            $r = $this->searchOne(['consulta' => $q] + $args);
            // De cada producto pedido interesan solo sus mejores coincidencias.
            $groups[] = ['consulta' => $q, 'status' => $r['status'], 'items' => array_slice($r['items'], 0, 3)];
            foreach (array_slice($r['items'], 0, 3) as $i) {
                $items[$i['nombre']] = $i;
            }
        }

        $statuses = array_column($groups, 'status');
        $status = match (true) {
            in_array('ambiguous', $statuses, true) => 'ambiguous',
            ! in_array('found', $statuses, true) => 'none',
            in_array('none', $statuses, true) || in_array('insufficient', $statuses, true) => 'partial',
            default => 'found',
        };

        return ['status' => $status, 'items' => array_values($items), 'resultados' => $groups];
    }

    private function searchOne(array $args): array
    {
        // Argumentos del modelo no confiables: un modelo pequeño puede mandar una lista donde va texto.
        $raw = $args['consulta'] ?? '';
        $query = Str::limit(trim(is_array($raw) ? implode(' ', array_filter($raw, 'is_scalar')) : (is_scalar($raw) ? (string) $raw : '')), 120, '');
        $category = isset($args['categoria']) && in_array($args['categoria'], ['impresoras', 'tintas', 'toners'], true)
            ? $args['categoria'] : null;
        $max = isset($args['precio_max']) && is_numeric($args['precio_max']) ? (float) $args['precio_max'] : null;
        $min = isset($args['precio_min']) && is_numeric($args['precio_min']) ? (float) $args['precio_min'] : null;
        $onlyStock = filter_var($args['solo_con_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $tokens = $this->tokens($query);

        if ($tokens === [] && $category === null && $max === null && $min === null) {
            return ['status' => 'insufficient', 'items' => []];
        }

        $scored = [];
        $products = Product::with('stock')->whereNull('deleted_at')->whereHas('stock')->get();

        foreach ($products as $product) {
            $stock = $product->stock;
            $price = (float) $stock->sale_price;
            $qty = (int) $stock->quantity;

            if ($category !== null && Str::lower((string) $product->category) !== $category) continue;
            if ($max !== null && $price > $max) continue;
            if ($min !== null && $price < $min) continue;
            if ($onlyStock && $qty <= 0) continue;

            $score = $tokens === [] ? 1.0 : $this->score($tokens, $product);
            if ($score >= 0.5) {
                $scored[] = [$score, $product];
            }
        }

        if ($scored === []) {
            return ['status' => 'none', 'items' => []];
        }

        usort($scored, fn($a, $b) => $b[0] <=> $a[0] ?: strcmp($a[1]->name, $b[1]->name));
        $scored = array_slice($scored, 0, 5);

        $top = $scored[0][0];
        $tied = count(array_filter($scored, fn($s) => $s[0] >= $top - 0.001));
        $second = $scored[1][0] ?? 0.0;
        // Un ganador claro (único y bien por encima del resto) no es ambiguo aunque haya coincidencias débiles.
        $clear = $tied === 1 && ($top - $second >= 0.25 || count($scored) <= 3);
        $status = $clear ? 'found' : 'ambiguous';
        if ($clear && $top - $second >= 0.25) {
            $scored = array_values(array_filter($scored, fn($s) => $s[0] >= $top - 0.25));
        }
        // Búsqueda sin texto (solo filtros): es un listado, no una ambigüedad.
        if ($tokens === []) $status = 'found';

        return ['status' => $status, 'items' => array_map(fn($s) => $this->toItem($s[1]), $scored)];
    }

    private function toItem(Product $p): array
    {
        $price = (float) $p->stock->sale_price;
        $qty = (int) $p->stock->quantity;
        $updated = $p->stock->updated_at ?? $p->updated_at;

        return [
            'nombre' => $p->name,
            'marca' => $p->brand,
            'categoria' => $p->category,
            'descripcion' => Str::limit(trim(strip_tags((string) $p->description)), 300),
            'precio' => $price,
            'precio_texto' => 'S/ ' . number_format($price, 2, '.', ','),
            'stock' => $qty,
            'disponibilidad' => $qty <= 0 ? 'agotado' : ($qty < 5 ? 'stock limitado' : 'disponible'),
            'actualizado' => $updated?->toDateString(),
            'dato_antiguo' => $updated !== null && $updated->lt(now()->subDays((int) config('chatbot.stale_days'))),
        ];
    }

    /** @return array<int, string> */
    public function tokens(string $text): array
    {
        $text = Str::lower(Str::ascii($text));
        $parts = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($parts as $part) {
            if (in_array($part, self::STOPWORDS, true)) continue;
            $out[] = $this->stem($part);
        }

        return array_values(array_unique($out));
    }

    private function stem(string $t): string
    {
        // Plural simple: toners→toner, impresoras→impresora. Tokens con dígitos (modelos) intactos.
        if (preg_match('/\d/', $t) || strlen($t) <= 3) return $t;
        if (str_ends_with($t, 'es') && strlen($t) > 5) return substr($t, 0, -2);
        return rtrim($t, 's');
    }

    private function score(array $queryTokens, Product $p): float
    {
        $haystack = $this->tokens(implode(' ', [$p->name, $p->brand, $p->category, $p->sku, $p->presentation]));
        // "fotocopiadora/multifuncional" son sinónimos comerciales de los equipos.
        $synonyms = ['fotocopiadora' => 'impresora', 'multifuncional' => 'impresora', 'copiadora' => 'impresora', 'cartucho' => 'toner'];

        $sum = 0.0;
        foreach ($queryTokens as $q) {
            $q = $synonyms[$q] ?? $q;
            $best = 0.0;
            foreach ($haystack as $h) {
                $h = $synonyms[$h] ?? $h;
                if ($q === $h) { $best = 1.0; break; }
                if (strlen($q) >= 3 && (str_starts_with($h, $q) || str_starts_with($q, $h)) && strlen($h) >= 3) $best = max($best, 0.8);
                $len = strlen($q);
                $dist = levenshtein($q, $h);
                if (($len >= 5 && $dist <= 1) || ($len >= 8 && $dist <= 2)) $best = max($best, 0.75);
            }
            $sum += $best;
        }

        return $sum / count($queryTokens);
    }
}
