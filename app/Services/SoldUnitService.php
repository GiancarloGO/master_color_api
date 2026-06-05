<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\SoldUnit;
use App\Services\AuditService;
use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SoldUnitService
{
    public function __construct(
        private AuditService $audit,
        private FileUploadService $files,
    ) {}

    /**
     * Genera las unidades vendidas a partir de una orden confirmada/pagada.
     *
     * Crea una unidad por cada ítem físico (respetando la cantidad), de modo que
     * cada unidad pueda recibir su propio número de serie, garantía y tickets.
     *
     * Idempotente: si la orden ya generó unidades, no las duplica.
     *
     * @return SoldUnit[]
     */
    public function generateFromOrder(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            if ($order->soldUnits()->exists()) {
                Log::info('Sold units already generated for order', ['order_id' => $order->id]);

                return [];
            }

            $order->loadMissing('orderDetails.product');
            $purchaseDate = Carbon::today();
            $created = [];

            foreach ($order->orderDetails as $detail) {
                $product = $detail->product;
                $warrantyMonths = (int) ($product->default_warranty_months ?? 0);
                $expiresAt = $this->computeExpiry($purchaseDate, $warrantyMonths);

                for ($i = 0; $i < $detail->quantity; $i++) {
                    $created[] = SoldUnit::create([
                        'client_id' => $order->client_id,
                        'product_id' => $detail->product_id,
                        'order_id' => $order->id,
                        'order_detail_id' => $detail->id,
                        'serial_number' => null,
                        'purchase_date' => $purchaseDate,
                        'warranty_months' => $warrantyMonths,
                        'warranty_expires_at' => $expiresAt,
                        'registration_source' => 'order',
                        'status' => 'activa',
                    ]);
                }
            }

            $this->audit->logSystemAction('sold_unit.generated_from_order', 'Order', $order->id, [
                'units_created' => count($created),
            ]);

            Log::info('Sold units generated from order', [
                'order_id' => $order->id,
                'units_created' => count($created),
            ]);

            return $created;
        });
    }

    /**
     * Registro manual de una unidad por parte del cliente (compra fuera del canal online).
     */
    public function registerManual(Client $client, array $data, ?UploadedFile $proof = null): SoldUnit
    {
        return DB::transaction(function () use ($client, $data, $proof) {
            $product = Product::findOrFail($data['product_id']);
            $warrantyMonths = (int) ($product->default_warranty_months ?? 0);
            $purchaseDate = Carbon::parse($data['purchase_date']);

            $proofPath = null;
            if ($proof) {
                $proofPath = $this->files->uploadImage($proof, 'sold-units/proofs', 'proof');
            }

            $unit = SoldUnit::create([
                'client_id' => $client->id,
                'product_id' => $product->id,
                'order_id' => null,
                'order_detail_id' => null,
                'serial_number' => $data['serial_number'] ?? null,
                'purchase_date' => $purchaseDate,
                'warranty_months' => $warrantyMonths,
                'warranty_expires_at' => $this->computeExpiry($purchaseDate, $warrantyMonths),
                'registration_source' => 'manual',
                'proof_file_path' => $proofPath,
                'status' => 'activa',
            ]);

            $this->audit->logClientAction($client, 'sold_unit.registered', 'SoldUnit', $unit->id, null, [
                'product_id' => $unit->product_id,
                'serial_number' => $unit->serial_number,
                'purchase_date' => $unit->purchase_date->toDateString(),
            ]);

            return $unit;
        });
    }

    /**
     * Calcula la fecha de vencimiento de la garantía (null si no hay garantía).
     */
    private function computeExpiry(Carbon $purchaseDate, int $warrantyMonths): ?Carbon
    {
        if ($warrantyMonths <= 0) {
            return null;
        }

        return $purchaseDate->copy()->addMonths($warrantyMonths);
    }
}
