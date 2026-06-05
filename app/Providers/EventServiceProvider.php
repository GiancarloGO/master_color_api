<?php

namespace App\Providers;

use App\Events\OrderStatusChanged;
use App\Events\TicketStatusChanged;
use App\Events\TicketMessageCreated;
use App\Events\TicketAssigned;
use App\Listeners\SendOrderStatusEmail;
use App\Listeners\NotifyTicketStatusChanged;
use App\Listeners\NotifyNewTicketMessage;
use App\Listeners\NotifyTicketAssigned;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OrderStatusChanged::class => [
            SendOrderStatusEmail::class,
        ],
        TicketStatusChanged::class => [
            NotifyTicketStatusChanged::class,
        ],
        TicketMessageCreated::class => [
            NotifyNewTicketMessage::class,
        ],
        TicketAssigned::class => [
            NotifyTicketAssigned::class,
        ],
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register model observers
        \App\Models\Order::observe(\App\Observers\OrderObserver::class);
    }
}
