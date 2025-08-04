<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PurchasesExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected $orders;
    protected $filters;

    public function __construct(Collection $orders, array $filters)
    {
        $this->orders = $orders;
        $this->filters = $filters;
    }

    public function collection()
    {
        return $this->orders;
    }

    public function headings(): array
    {
        return [
            'ID Orden',
            'Fecha Compra',
            'Cliente',
            'Email',
            'Teléfono',
            'Tipo Cliente',
            'Estado Orden',
            'Subtotal',
            'Costo Envío',
            'Descuento',
            'Total',
            'Método Pago',
            'Productos Comprados'
        ];
    }

    public function map($order): array
    {
        $products = $order->orderDetails->map(function ($detail) {
            return $detail->product->name . ' (Cantidad: ' . $detail->quantity . ', Precio: $' . number_format($detail->price, 2) . ')';
        })->join(' | ');

        return [
            $order->id,
            $order->created_at->format('d/m/Y H:i'),
            $order->client->name ?? 'N/A',
            $order->client->email ?? 'N/A',
            $order->client->phone ?? 'N/A',
            ucfirst($order->client->client_type ?? 'individual'),
            ucfirst($order->status),
            '$' . number_format($order->subtotal, 2),
            '$' . number_format($order->shipping_cost, 2),
            '$' . number_format($order->discount, 2),
            '$' . number_format($order->total, 2),
            $order->codigo_payment ?? 'N/A',
            $products
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Reporte de Compras';
    }
}