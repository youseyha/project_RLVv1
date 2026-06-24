<?php

namespace App\Listeners;

use App\Events\LowStockDetected;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendLowStockNotification implements ShouldQueue
{
    public function handle(LowStockDetected $event): void
    {
        // Get tenant from branch
        $tenant = $event->branch->tenant;

        // Find admin & manager users
        $users = User::where('tenant_id', $tenant->tenant_id)
            ->whereIn('role', ['admin', 'manager'])
            ->where('is_active', true)
            ->get();

        // Send notification
        foreach ($users as $user) {
            $user->notify(new LowStockNotification($event->inventory));
        }
    }
}
//php artisan queue:work