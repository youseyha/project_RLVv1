<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Branch;
use App\Models\Branches;
use App\Models\User;
use App\Models\PosTerminal;
use App\Models\Transaction;
use Carbon\Carbon;

class UsageTrackingService
{
    /**
     * GET CURRENT USAGE - ការប្រើប្រាស់បច្ចុប្បន្ន
     */
    public function getCurrentUsage(string $tenantId): array
    {
        // Get active subscription
        $subscription = Subscription::with('plan')
            ->where('tenant_id', $tenantId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        if (!$subscription) {
            return [
                'has_subscription' => false,
                'message' => 'No active subscription',
            ];
        }

        // Count current usage
        $usage = [
            'branches' => [
                'current' => Branches::where('tenant_id', $tenantId)->count(),
                'limit' => $subscription->plan->max_branches,
                'unlimited' => $subscription->plan->max_branches === 0,
            ],
            'users' => [
                'current' => User::where('tenant_id', $tenantId)->count(),
                'limit' => $subscription->plan->max_users,
                'unlimited' => $subscription->plan->max_users === 0,
            ],
            'terminals' => [
                'current' => PosTerminal::whereHas('branch', function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                })->count(),
                'limit' => $subscription->plan->max_pos_terminals,
                'unlimited' => $subscription->plan->max_pos_terminals === 0,
            ],
            'transactions' => [
                'current' => Transaction::whereHas('branch', function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                })
                ->whereMonth('transaction_date', now()->month)
                ->whereYear('transaction_date', now()->year)
                ->count(),
                'limit' => $subscription->plan->transaction_limit_monthly,
                'unlimited' => $subscription->plan->transaction_limit_monthly === 0,
                'period' => 'monthly',
            ],
        ];

        // Calculate percentages
        foreach ($usage as $key => &$metric) {
            if (!$metric['unlimited'] && $metric['limit'] > 0) {
                $metric['percentage'] = round(($metric['current'] / $metric['limit']) * 100, 2);
                $metric['remaining'] = $metric['limit'] - $metric['current'];
                $metric['status'] = $this->getUsageStatus($metric['percentage']);
            } else {
                $metric['percentage'] = 0;
                $metric['remaining'] = 'unlimited';
                $metric['status'] = 'healthy';
            }
        }

        return [
            'has_subscription' => true,
            'subscription_plan' => $subscription->plan->plan_name,
            'plan_id' => $subscription->plan->plan_id,
            'usage' => $usage,
            'overall_status' => $this->getOverallStatus($usage),
        ];
    }

    /**
     * GET USAGE HISTORY - ប្រវត្តិការប្រើប្រាស់
     */
    public function getUsageHistory(
        string $tenantId,
        string $dateFrom,
        string $dateTo,
        ?string $metric = null
    ): array {
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        $history = [];

        // Generate daily data points
        for ($date = $start->copy(); $date <= $end; $date->addDay()) {
            $point = [
                'date' => $date->format('Y-m-d'),
            ];

            if (!$metric || $metric === 'transactions') {
                $point['transactions'] = Transaction::whereHas('branch', function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                })
                ->whereDate('transaction_date', $date)
                ->count();
            }

            if (!$metric || $metric === 'users') {
                $point['users'] = User::where('tenant_id', $tenantId)
                    ->where('created_at', '<=', $date)
                    ->count();
            }

            if (!$metric || $metric === 'branches') {
                $point['branches'] = Branches::where('tenant_id', $tenantId)
                    ->where('created_at', '<=', $date)
                    ->count();
            }

            if (!$metric || $metric === 'terminals') {
                $point['terminals'] = PosTerminal::whereHas('branch', function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                })
                ->where('created_at', '<=', $date)
                ->count();
            }

            $history[] = $point;
        }

        return [
            'period' => [
                'from' => $dateFrom,
                'to' => $dateTo,
                'days' => $start->diffInDays($end) + 1,
            ],
            'metric' => $metric ?? 'all',
            'data' => $history,
        ];
    }

    /**
     * CHECK LIMIT - ពិនិត្យកម្រិត
     */
    public function checkLimit(string $tenantId, string $limitType): array
    {
        $subscription = Subscription::with('plan')
            ->where('tenant_id', $tenantId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        if (!$subscription) {
            return [
                'has_subscription' => false,
                'within_limit' => false,
            ];
        }

        $currentCount = match($limitType) {
            'branches' => Branches::where('tenant_id', $tenantId)->count(),
            'users' => User::where('tenant_id', $tenantId)->count(),
            'terminals' => PosTerminal::whereHas('branch', function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })->count(),
            'transactions' => Transaction::whereHas('branch', function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->whereMonth('transaction_date', now()->month)
            ->count(),
            default => 0,
        };

        $limit = match($limitType) {
            'branches' => $subscription->plan->max_branches,
            'users' => $subscription->plan->max_users,
            'terminals' => $subscription->plan->max_pos_terminals,
            'transactions' => $subscription->plan->transaction_limit_monthly,
            default => 0,
        };

        $withinLimit = $limit === 0 || $currentCount < $limit;
        $percentage = $limit > 0 ? ($currentCount / $limit) * 100 : 0;

        return [
            'has_subscription' => true,
            'limit_type' => $limitType,
            'current' => $currentCount,
            'limit' => $limit,
            'unlimited' => $limit === 0,
            'within_limit' => $withinLimit,
            'percentage_used' => round($percentage, 2),
            'remaining' => $limit > 0 ? $limit - $currentCount : 'unlimited',
            'status' => $this->getUsageStatus($percentage),
        ];
    }

    /**
     * GET USAGE ALERTS - ការព្រមាន
     */
    public function getUsageAlerts(string $tenantId): array
    {
        $usage = $this->getCurrentUsage($tenantId);

        if (!$usage['has_subscription']) {
            return ['alerts' => []];
        }

        $alerts = [];

        foreach ($usage['usage'] as $type => $metric) {
            if (!$metric['unlimited'] && isset($metric['percentage'])) {
                if ($metric['percentage'] >= 90) {
                    $alerts[] = [
                        'type' => $type,
                        'level' => 'critical',
                        'message' => "You have used {$metric['percentage']}% of your {$type} limit",
                        'current' => $metric['current'],
                        'limit' => $metric['limit'],
                        'action' => 'upgrade',
                    ];
                } elseif ($metric['percentage'] >= 75) {
                    $alerts[] = [
                        'type' => $type,
                        'level' => 'warning',
                        'message' => "You have used {$metric['percentage']}% of your {$type} limit",
                        'current' => $metric['current'],
                        'limit' => $metric['limit'],
                        'action' => 'consider_upgrade',
                    ];
                }
            }
        }

        return [
            'alerts' => $alerts,
            'count' => count($alerts),
            'has_critical' => collect($alerts)->where('level', 'critical')->isNotEmpty(),
        ];
    }

    /**
     * GET FORECAST - ការព្យាករណ៍
     */
    public function getForecast(string $tenantId): array
    {
        // Get last 30 days of transaction data
        $last30Days = Transaction::whereHas('branch', function($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId);
        })
        ->where('transaction_date', '>=', now()->subDays(30))
        ->count();

        $dailyAverage = $last30Days / 30;
        $monthlyEstimate = $dailyAverage * 30;

        $subscription = Subscription::with('plan')
            ->where('tenant_id', $tenantId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        $forecast = [
            'transactions' => [
                'daily_average' => round($dailyAverage, 2),
                'monthly_estimate' => round($monthlyEstimate, 0),
                'days_until_limit' => null,
            ],
        ];

        if ($subscription && $subscription->plan->transaction_limit_monthly > 0) {
            $currentCount = Transaction::whereHas('branch', function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->whereMonth('transaction_date', now()->month)
            ->count();

            $remaining = $subscription->plan->transaction_limit_monthly - $currentCount;
            $daysUntilLimit = $dailyAverage > 0 ? $remaining / $dailyAverage : null;

            $forecast['transactions']['days_until_limit'] = $daysUntilLimit ? round($daysUntilLimit, 1) : null;
            $forecast['transactions']['will_exceed'] = $monthlyEstimate > $subscription->plan->transaction_limit_monthly;
        }

        return $forecast;
    }

    /**
     * HELPER METHODS
     */
    
    protected function getUsageStatus(float $percentage): string
    {
        return match(true) {
            $percentage >= 90 => 'critical',
            $percentage >= 75 => 'warning',
            $percentage >= 50 => 'moderate',
            default => 'healthy',
        };
    }

    protected function getOverallStatus(array $usage): string
    {
        $statuses = array_column($usage, 'status');

        if (in_array('critical', $statuses)) {
            return 'critical';
        }

        if (in_array('warning', $statuses)) {
            return 'warning';
        }

        if (in_array('moderate', $statuses)) {
            return 'moderate';
        }

        return 'healthy';
    }
}