<?php

namespace App\Providers;

use App\Events\LowStockDetected;
use App\Listeners\SendLowStockNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        LowStockDetected::class => [
            SendLowStockNotification::class,
        ],
    ];

    public function boot(): void
    {
        //
    }
}