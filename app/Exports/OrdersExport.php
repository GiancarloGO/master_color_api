<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrdersExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
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
            'ID',
            'Fecha Creación',
            'Cliente',
            'Vendedor',
            'Estado',
            'Subtotal',
            'Envío',
            'Descuento',
            'Total',
            'Items',
            'Dirección Entrega',
            'Observaciones',
            'Código Pago'
        ];
    }

    public function map($order): array
    {
        $itemsCount = $order->orderDetails->sum('quantity');
        $deliveryAddress = $order->deliveryAddress 
            ? $order->deliveryAddress->street . ', ' . $order->deliveryAddress->city 
            : 'Sin dirección';

        return [
            $order->id,
            $order->created_at->format('d/m/Y H:i:s'),
            $order->client->name ?? 'Cliente no registrado',
            $order->user->name ?? 'Sin asignar',
            ucfirst($order->status),
            'S/. ' . number_format($order->subtotal, 2),
            'S/. ' . number_format($order->shipping_cost, 2),
            'S/. ' . number_format($order->discount, 2),
            'S/. ' . number_format($order->total, 2),
            $itemsCount . ' items',
            $deliveryAddress,
            $order->observations ?? 'Sin observaciones',
            $order->codigo_payment ?? 'Sin código'
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
        return 'Reporte de Órdenes';
    }
}