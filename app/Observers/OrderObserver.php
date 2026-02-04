<?php

namespace App\Observers;

use App\Models\Order;
use App\Events\OrderStatusChanged;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    /**
     * Store previous status values temporarily
     * Key is order ID, value is previous status
     */
    private static $previousStatuses = [];

    /**
     * Handle the Order "updating" event.
     * This fires before the model is saved, allowing us to capture the old status.
     */
    public function updating(Order $order): void
    {
        // Check if the status field is being changed
        if ($order->isDirty('status')) {
            $previousStatus = $order->getOriginal('status');
            $newStatus = $order->status;
            
            // Only store if status actually changed
            if ($previousStatus !== $newStatus) {
                Log::info("Order status changing", [
                    'order_id' => $order->id,
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus
                ]);
                
                // Store the previous status in static property
                self::$previousStatuses[$order->id] = $previousStatus;
            }
        }
    }

    /**
     * Handle the Order "updated" event.
     * This fires after the model is saved.
     */
    public function updated(Order $order): void
    {
        // Check if we stored a previous status (meaning status changed)
        if (isset(self::$previousStatuses[$order->id])) {
            $previousStatus = self::$previousStatuses[$order->id];
            $newStatus = $order->status;
            
            Log::info("Dispatching OrderStatusChanged event", [
                'order_id' => $order->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus
            ]);
            
            // Dispatch the event for email notification
            event(new OrderStatusChanged($order, $previousStatus, $newStatus));
            
            // Clean up the stored status
            unset(self::$previousStatuses[$order->id]);
        }
    }
}
