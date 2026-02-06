<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanOrphanPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:clean-orphans 
                            {--hours=24 : Hours after which pending payments are considered orphans}
                            {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean orphan pending payments older than specified hours and mark their orders as failed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = $this->option('hours');
        $dryRun = $this->option('dry-run');
        
        $this->info("Searching for orphan payments older than {$hours} hours...");
        
        $cutoffTime = Carbon::now()->subHours($hours);
        
        // Find orphan payments
        $orphanPayments = Payment::where('status', 'pending')
            ->where('created_at', '<', $cutoffTime)
            ->with('order')
            ->get();
        
        if ($orphanPayments->isEmpty()) {
            $this->info('No orphan payments found.');
            return 0;
        }
        
        $this->warn("Found {$orphanPayments->count()} orphan payment(s)");
        
        $table = [];
        foreach ($orphanPayments as $payment) {
            $table[] = [
                'Payment ID' => $payment->id,
                'Order ID' => $payment->order_id,
                'Amount' => $payment->currency . ' ' . number_format($payment->amount, 2),
                'Created' => $payment->created_at->diffForHumans(),
                'Order Status' => $payment->order->status ?? 'N/A'
            ];
        }
        
        $this->table(
            ['Payment ID', 'Order ID', 'Amount', 'Created', 'Order Status'],
            $table
        );
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            return 0;
        }
        
        if (!$this->confirm('Do you want to clean these orphan payments?', true)) {
            $this->info('Operation cancelled.');
            return 0;
        }
        
        $cleaned = 0;
        $errors = 0;
        
        foreach ($orphanPayments as $payment) {
            try {
                // Update payment status
                $payment->update(['status' => 'cancelled']);
                
                // Update order status if it's still pending payment
                $order = $payment->order;
                if ($order && in_array($order->status, ['pendiente_pago'])) {
                    $order->update([
                        'status' => 'pago_fallido',
                        'observations' => 'Pago expirado - limpieza automática'
                    ]);
                }
                
                Log::info('Orphan payment cleaned', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'hours_old' => $payment->created_at->diffInHours(now())
                ]);
                
                $cleaned++;
                
            } catch (\Exception $e) {
                $this->error("Error cleaning payment {$payment->id}: {$e->getMessage()}");
                Log::error('Error cleaning orphan payment', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage()
                ]);
                $errors++;
            }
        }
        
        $this->info("Successfully cleaned {$cleaned} orphan payment(s)");
        
        if ($errors > 0) {
            $this->error("Failed to clean {$errors} payment(s)");
            return 1;
        }
        
        return 0;
    }
}
