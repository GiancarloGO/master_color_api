<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Mail\OrderStatusNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendOrderStatusEmail
{

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderStatusChanged $event): void
    {
        try {
            // Load the order with client and order details relationships
            $order = $event->order->load(['client', 'orderDetails.product']);
            
            // Check if client has email and is verified
            if (!$order->client->email) {
                Log::warning("Order {$order->id} client has no email address");
                return;
            }

            // Send the email notification
            Mail::to($order->client->email)
                ->send(new OrderStatusNotification($order, $event->previousStatus));
            
            Log::info("Order status email queued for order {$order->id} to {$order->client->email}");
            
        } catch (\Exception $e) {
            Log::error("Failed to send order status email for order {$event->order->id}: " . $e->getMessage());
            
            // Optionally, you can choose to fail the job to retry later
            // throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(OrderStatusChanged $event, \Throwable $exception): void
    {
        Log::error("Order status email job failed for order {$event->order->id}: " . $exception->getMessage());
    }
}
