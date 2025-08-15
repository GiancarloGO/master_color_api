<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .filters {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .summary {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .summary-item {
            text-align: center;
            padding: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
            flex: 1;
            margin: 0 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #343a40;
            color: white;
            font-weight: bold;
        }
        .total-row {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <p>Master Color API - Sistema de Gestión</p>
        <p>Generado el: {{ $generated_at }}</p>
    </div>

    <div class="filters">
        <h3>Filtros Aplicados:</h3>
        @if(!empty($filters['start_date']))
            <p><strong>Fecha Inicio:</strong> {{ \Carbon\Carbon::parse($filters['start_date'])->format('d/m/Y') }}</p>
        @endif
        @if(!empty($filters['end_date']))
            <p><strong>Fecha Fin:</strong> {{ \Carbon\Carbon::parse($filters['end_date'])->format('d/m/Y') }}</p>
        @endif
        @if(!empty($filters['client_id']))
            <p><strong>Cliente ID:</strong> {{ $filters['client_id'] }}</p>
        @endif
        @if(!empty($filters['status']))
            <p><strong>Estado:</strong> {{ ucfirst($filters['status']) }}</p>
        @endif
    </div>

    <div class="summary">
        <div class="summary-item">
            <h4>Total Compras</h4>
            <p>{{ $total_orders }}</p>
        </div>
        <div class="summary-item">
            <h4>Monto Total</h4>
            <p>S/. {{ number_format($total_amount, 2) }}</p>
        </div>
        <div class="summary-item">
            <h4>Promedio por Compra</h4>
            <p>S/. {{ $total_orders > 0 ? number_format($total_amount / $total_orders, 2) : '0.00' }}</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Email</th>
                <th>Estado</th>
                <th>Productos</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($orders as $order)
            <tr>
                <td>{{ $order->id }}</td>
                <td>{{ $order->created_at->format('d/m/Y') }}</td>
                <td>{{ $order->client->name ?? 'Cliente no registrado' }}</td>
                <td>{{ $order->client->email ?? 'Sin email' }}</td>
                <td>{{ ucfirst($order->status) }}</td>
                <td>{{ $order->orderDetails->sum('quantity') }} items</td>
                <td>S/. {{ number_format($order->total, 2) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="6"><strong>TOTAL</strong></td>
                <td><strong>S/. {{ number_format($orders->sum('total'), 2) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p>Este reporte fue generado automáticamente por el sistema Master Color API</p>
    </div>
</body>
</html>