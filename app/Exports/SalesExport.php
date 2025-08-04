<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
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
            'Fecha',
            'Cliente',
            'Email Cliente',
            'Vendedor',
            'Estado',
            'Subtotal',
            'Costo Envío',
            'Descuento',
            'Total',
            'Productos',
            'Código Pago'
        ];
    }

    public function map($order): array
    {
        $products = $order->orderDetails->map(function ($detail) {
            return $detail->product->name . ' (Qty: ' . $detail->quantity . ')';
        })->join(', ');

        return [
            $order->id,
            $order->created_at->format('d/m/Y H:i'),
            $order->client->name ?? 'N/A',
            $order->client->email ?? 'N/A',
            $order->user->name ?? 'N/A',
            ucfirst($order->status),
            number_format($order->subtotal, 2),
            number_format($order->shipping_cost, 2),
            number_format($order->discount, 2),
            number_format($order->total, 2),
            $products,
            $order->codigo_payment ?? 'N/A'
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
        return 'Reporte de Ventas';
    }
}