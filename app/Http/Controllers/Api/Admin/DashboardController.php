<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderStatusService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const DEFAULT_HOURLY_WINDOW = 24;
    private const MAX_HOURLY_WINDOW = 48;
    private const DEFAULT_MONTHLY_WINDOW = 12;

    public function __construct(private readonly OrderStatusService $orderStatusService)
    {
    }

    /**
     * Get dashboard statistics
     */
    public function index(Request $request)
    {
        try {
            $windowHours = (int) $request->query('hours', self::DEFAULT_HOURLY_WINDOW);
            $windowHours = max(1, min(self::MAX_HOURLY_WINDOW, $windowHours));
            $this->orderStatusService->ensureDefaultStatuses();

            $statusBreakdown = $this->buildOrderStatusBreakdown();
            $totalOrderStats = $this->sumStatusBuckets($statusBreakdown, array_keys($statusBreakdown));
            $activeOrderStats = $this->sumStatusBuckets($statusBreakdown, ['new_order', 'no_response', 'hold']);
            $completedOrderStats = $this->sumStatusBuckets($statusBreakdown, ['complete', 'fb_sent']);
            $newOrderStats = $this->sumStatusBuckets($statusBreakdown, ['new_order']);
            $noResponseOrderStats = $this->sumStatusBuckets($statusBreakdown, ['no_response']);
            $holdOrderStats = $this->sumStatusBuckets($statusBreakdown, ['hold']);
            $cancelledOrderStats = $this->sumStatusBuckets($statusBreakdown, ['cancel']);
            $inCourierOrderStats = $this->sumStatusBuckets($statusBreakdown, ['fb_sent']);

            return response()->json([
                'success' => true,
                'generated_at' => Carbon::now()->toIso8601String(),
                'stats' => [
                    'total_order' => $totalOrderStats,
                    'active_order' => $activeOrderStats,
                    'completed_order' => $completedOrderStats,
                    'new_order' => $newOrderStats,
                    'no_response_order' => $noResponseOrderStats,
                    'hold_order' => $holdOrderStats,
                    'cancelled_order' => $cancelledOrderStats,
                    'in_courier_order' => $inCourierOrderStats,
                    'today_order' => $this->getTodayOrderStats(),
                    'total_product' => Product::count(),
                    'total_customer' => Customer::count(),
                ],
                'order_status_breakdown' => collect($statusBreakdown)
                    ->map(function (array $bucket, string $key) {
                        return [
                            'key' => $key,
                            'label' => $bucket['label'],
                            'count' => (int) $bucket['count'],
                            'amount' => (int) $bucket['amount'],
                        ];
                    })
                    ->values(),
                'hourly_orders' => $this->buildHourlyOrderAnalytics($windowHours),
                'monthly_orders' => $this->buildMonthlyOrderAnalytics(self::DEFAULT_MONTHLY_WINDOW),
                'latest_orders' => Order::with(['customer:id,name,phone', 'orderdetails:id,order_id,image'])
                    ->latest()
                    ->limit(50)
                    ->get()
                    ->map(function ($order) {
                        $statusKey = $this->normalizeOrderStatus((string) ($order->order_status ?? ''));
                        $imagePath = null;
                        if ($order->relationLoaded('orderdetails') && $order->orderdetails && $order->orderdetails->count() > 0) {
                            $imagePath = $order->orderdetails->first()->image ?? null;
                        }

                        return [
                            'id' => $order->id,
                            'invoice_id' => $order->invoice_id,
                            'amount' => $order->amount,
                            'status_key' => $statusKey,
                            'status_label' => $this->statusLabelForKey($statusKey),
                            'customer' => $order->customer ? [
                                'name' => $order->customer->name,
                                'phone' => $order->customer->phone,
                            ] : null,
                            'product_image' => $this->resolveImageUrl($imagePath),
                            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
                            'time_ago' => $order->created_at?->diffForHumans(),
                        ];
                    }),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching dashboard data: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function buildOrderStatusBreakdown(): array
    {
        $buckets = $this->statusBucketsTemplate();

        $rows = Order::query()
            ->selectRaw("LOWER(TRIM(COALESCE(order_status, ''))) as raw_status")
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->groupByRaw("LOWER(TRIM(COALESCE(order_status, '')))")
            ->get();

        foreach ($rows as $row) {
            $bucketKey = $this->normalizeOrderStatus((string) $row->raw_status);

            $buckets[$bucketKey]['count'] += (int) $row->order_count;
            $buckets[$bucketKey]['amount'] += (int) $row->total_amount;
        }

        return $buckets;
    }

    private function statusBucketsTemplate(): array
    {
        $buckets = [];

        foreach ($this->orderStatusService->supportedCanonicalKeys() as $canonicalKey) {
            $buckets[$canonicalKey] = [
                'label' => $this->orderStatusService->canonicalLabel($canonicalKey),
                'count' => 0,
                'amount' => 0,
            ];
        }

        return $buckets;
    }

    private function normalizeOrderStatus(string $status): string
    {
        return $this->orderStatusService->canonicalKeyFromValue($status) ?? 'new_order';
    }

    private function statusLabelForKey(string $statusKey): string
    {
        return $this->orderStatusService->canonicalLabel($statusKey);
    }

    private function sumStatusBuckets(array $buckets, array $keys): array
    {
        $count = 0;
        $amount = 0;

        foreach ($keys as $key) {
            if (!isset($buckets[$key])) {
                continue;
            }

            $count += (int) $buckets[$key]['count'];
            $amount += (int) $buckets[$key]['amount'];
        }

        return [
            'count' => $count,
            'amount' => $amount,
        ];
    }

    private function getTodayOrderStats(): array
    {
        $today = Order::query()
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->where('created_at', '>=', Carbon::today())
            ->first();

        return [
            'count' => (int) ($today->order_count ?? 0),
            'amount' => (int) ($today->total_amount ?? 0),
        ];
    }

    private function buildHourlyOrderAnalytics(int $windowHours): array
    {
        $startAt = Carbon::now()->subHours($windowHours - 1)->startOfHour();
        $endAt = Carbon::now();
        $buckets = [];

        for ($hourOffset = 0; $hourOffset < $windowHours; $hourOffset++) {
            $hour = $startAt->copy()->addHours($hourOffset);
            $key = $hour->format('Y-m-d H:00:00');

            $buckets[$key] = [
                'hour_start' => $hour->toDateTimeString(),
                'hour_label' => $hour->format('g A'),
                'order_count' => 0,
                'amount' => 0,
            ];
        }

        $driver = DB::connection()->getDriverName();
        $hourBucketExpression = $this->hourBucketExpression($driver);

        if ($hourBucketExpression === null) {
            $orders = Order::query()
                ->select(['created_at', 'amount'])
                ->whereBetween('created_at', [$startAt, $endAt])
                ->get();

            foreach ($orders as $order) {
                if (!$order->created_at) {
                    continue;
                }

                $key = Carbon::parse($order->created_at)->startOfHour()->format('Y-m-d H:00:00');
                if (!isset($buckets[$key])) {
                    continue;
                }

                $buckets[$key]['order_count'] += 1;
                $buckets[$key]['amount'] += (int) ($order->amount ?? 0);
            }
        } else {
            $rows = Order::query()
                ->selectRaw($hourBucketExpression . ' as hour_bucket')
                ->selectRaw('COUNT(*) as order_count')
                ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
                ->whereBetween('created_at', [$startAt, $endAt])
                ->groupByRaw($hourBucketExpression)
                ->orderByRaw($hourBucketExpression)
                ->get();

            foreach ($rows as $row) {
                if (empty($row->hour_bucket)) {
                    continue;
                }

                $key = Carbon::parse((string) $row->hour_bucket)
                    ->startOfHour()
                    ->format('Y-m-d H:00:00');
                if (!isset($buckets[$key])) {
                    continue;
                }

                $buckets[$key]['order_count'] = (int) $row->order_count;
                $buckets[$key]['amount'] = (int) $row->total_amount;
            }
        }

        return [
            'window_hours' => $windowHours,
            'start_at' => $startAt->toDateTimeString(),
            'end_at' => $endAt->toDateTimeString(),
            'data' => array_values($buckets),
        ];
    }

    private function hourBucketExpression(string $driver): ?string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')",
            'pgsql' => "TO_CHAR(DATE_TRUNC('hour', created_at), 'YYYY-MM-DD HH24:00:00')",
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', created_at)",
            default => null,
        };
    }

    private function buildMonthlyOrderAnalytics(int $windowMonths): array
    {
        $windowMonths = max(1, $windowMonths);
        $startAt = Carbon::now()->startOfMonth()->subMonths($windowMonths - 1);
        $endAt = Carbon::now()->endOfMonth();
        $buckets = [];

        for ($monthOffset = 0; $monthOffset < $windowMonths; $monthOffset++) {
            $month = $startAt->copy()->addMonths($monthOffset);
            $key = $month->format('Y-m');

            $buckets[$key] = [
                'month' => $key,
                'month_label' => $month->format('M Y'),
                'order_count' => 0,
                'amount' => 0,
            ];
        }

        $driver = DB::connection()->getDriverName();
        $monthBucketExpression = $this->monthBucketExpression($driver);

        if ($monthBucketExpression === null) {
            $orders = Order::query()
                ->select(['created_at', 'amount'])
                ->whereBetween('created_at', [$startAt, $endAt])
                ->get();

            foreach ($orders as $order) {
                if (!$order->created_at) {
                    continue;
                }

                $key = Carbon::parse($order->created_at)->startOfMonth()->format('Y-m');
                if (!isset($buckets[$key])) {
                    continue;
                }

                $buckets[$key]['order_count'] += 1;
                $buckets[$key]['amount'] += (int) ($order->amount ?? 0);
            }
        } else {
            $rows = Order::query()
                ->selectRaw($monthBucketExpression . ' as month_bucket')
                ->selectRaw('COUNT(*) as order_count')
                ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
                ->whereBetween('created_at', [$startAt, $endAt])
                ->groupByRaw($monthBucketExpression)
                ->orderByRaw($monthBucketExpression)
                ->get();

            foreach ($rows as $row) {
                if (empty($row->month_bucket)) {
                    continue;
                }

                $key = Carbon::parse((string) $row->month_bucket . '-01')
                    ->startOfMonth()
                    ->format('Y-m');

                if (!isset($buckets[$key])) {
                    continue;
                }

                $buckets[$key]['order_count'] = (int) $row->order_count;
                $buckets[$key]['amount'] = (int) $row->total_amount;
            }
        }

        return [
            'window_months' => $windowMonths,
            'start_at' => $startAt->toDateTimeString(),
            'end_at' => $endAt->toDateTimeString(),
            'data' => array_values($buckets),
        ];
    }

    private function monthBucketExpression(string $driver): ?string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "DATE_FORMAT(created_at, '%Y-%m')",
            'pgsql' => "TO_CHAR(DATE_TRUNC('month', created_at), 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', created_at)",
            default => null,
        };
    }

    private function resolveImageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (preg_match('/^https?:\\/\\//i', $path)) {
            return $path;
        }

        $normalized = ltrim($path, '/');
        if ($normalized === 'default.png') {
            return null;
        }

        return asset($normalized);
    }
}
