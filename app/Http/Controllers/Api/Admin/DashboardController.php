<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderStatusService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $dateRange = $this->resolveOrderDateRange($request);
            $this->orderStatusService->ensureDefaultStatuses();

            $statusBreakdown = $this->buildOrderStatusBreakdown($dateRange);
            $totalOrderStats = $this->sumStatusBuckets($statusBreakdown, array_keys($statusBreakdown));
            $activeOrderStats = $this->sumStatusBuckets($statusBreakdown, ['new_order', 'no_response', 'hold']);
            $completedOrderStats = $this->sumStatusBuckets($statusBreakdown, ['complete', 'fb_sent']);
            $newOrderStats = $this->sumStatusBuckets($statusBreakdown, ['new_order']);
            $noResponseOrderStats = $this->sumStatusBuckets($statusBreakdown, ['no_response']);
            $holdOrderStats = $this->sumStatusBuckets($statusBreakdown, ['hold']);
            $cancelledOrderStats = $this->sumStatusBuckets($statusBreakdown, ['cancel']);
            $fbSentOrderStats = $this->sumStatusBuckets($statusBreakdown, ['fb_sent']);
            $inCourierOrderStats = $this->getInCourierOrderStats($dateRange);
            $latestOrdersQuery = Order::with(['customer:id,name,phone', 'orderdetails:id,order_id,image'])
                ->latest();

            $this->applyDateRangeToOrderQuery($latestOrdersQuery, $dateRange);

            return response()->json([
                'success' => true,
                'generated_at' => Carbon::now()->toIso8601String(),
                'filters' => [
                    'start_date' => $dateRange['start_date'],
                    'end_date' => $dateRange['end_date'],
                ],
                'stats' => [
                    'total_order' => $totalOrderStats,
                    'active_order' => $activeOrderStats,
                    'completed_order' => $completedOrderStats,
                    'new_order' => $newOrderStats,
                    'no_response_order' => $noResponseOrderStats,
                    'hold_order' => $holdOrderStats,
                    'cancelled_order' => $cancelledOrderStats,
                    'fb_sent_order' => $fbSentOrderStats,
                    'in_courier_order' => $inCourierOrderStats,
                    'today_order' => $this->getTodayOrderStats($dateRange),
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
                'hourly_orders' => $this->buildHourlyOrderAnalytics($windowHours, $dateRange),
                'monthly_orders' => $this->buildMonthlyOrderAnalytics(self::DEFAULT_MONTHLY_WINDOW, $dateRange),
                'latest_orders' => $latestOrdersQuery
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


public function products(Request $request)
{
    try {
        $products = DB::table('order_details')
            ->join('products', 'order_details.product_id', '=', 'products.id')
            ->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->select(
                'products.id as product_id',
                'products.name',
                DB::raw('MAX(order_details.image) as image'),
                DB::raw('SUM(order_details.qty) as quantity_sold'),
                DB::raw('SUM(order_details.sale_price * order_details.qty) as total_sale'),
                DB::raw('COUNT(DISTINCT order_details.order_id) as total_orders')
            )
            ->where('orders.order_status', 10) 
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('quantity_sold')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error fetching products: ' . $e->getMessage(),
        ], 500);
    }
}


    private function buildOrderStatusBreakdown(array $dateRange = []): array
    {
        $buckets = $this->statusBucketsTemplate();

        $query = Order::query()
            ->selectRaw("LOWER(TRIM(COALESCE(order_status, ''))) as raw_status")
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount');

        $this->applyDateRangeToOrderQuery($query, $dateRange);

        $rows = $query
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

    private function getTodayOrderStats(array $dateRange = []): array
    {
        $query = Order::query()
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->where('created_at', '>=', Carbon::today());

        $this->applyDateRangeToOrderQuery($query, $dateRange);

        $today = $query->first();

        return [
            'count' => (int) ($today->order_count ?? 0),
            'amount' => (int) ($today->total_amount ?? 0),
        ];
    }

    private function getInCourierOrderStats(array $dateRange = []): array
    {
        if (!Schema::hasColumn('orders', 'courier_name')) {
            return [
                'count' => 0,
                'amount' => 0,
            ];
        }

        $query = Order::query()
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->whereNotNull('courier_name')
            ->where('courier_name', '!=', '');

        if (Schema::hasColumn('orders', 'courier_order_id')) {
            $query->where(function (Builder $courierQuery) {
                $courierQuery->whereNotNull('courier_order_id')
                    ->where('courier_order_id', '!=', '');

                if (Schema::hasColumn('orders', 'courier_status')) {
                    $courierQuery->orWhere(function (Builder $statusQuery) {
                        $statusQuery->whereNotNull('courier_status')
                            ->where('courier_status', '!=', '')
                            ->whereIn(DB::raw('LOWER(TRIM(courier_status))'), [
                                'sent',
                                'booked',
                                'created',
                                'processing',
                                'in_transit',
                                'in-transit',
                                'pending_pickup',
                                'picked',
                                'success',
                            ]);
                    });
                }
            });
        } elseif (Schema::hasColumn('orders', 'courier_status')) {
            $query->whereNotNull('courier_status')
                ->where('courier_status', '!=', '')
                ->whereIn(DB::raw('LOWER(TRIM(courier_status))'), [
                    'sent',
                    'booked',
                    'created',
                    'processing',
                    'in_transit',
                    'in-transit',
                    'pending_pickup',
                    'picked',
                    'success',
                ]);
        }

        $this->applyDateRangeToOrderQuery($query, $dateRange);

        $stats = $query->first();

        return [
            'count' => (int) ($stats->order_count ?? 0),
            'amount' => (int) ($stats->total_amount ?? 0),
        ];
    }

    private function buildHourlyOrderAnalytics(int $windowHours, array $dateRange = []): array
    {
        [$startAt, $endAt, $effectiveWindowHours] = $this->resolveHourlyWindowBounds($windowHours, $dateRange);
        $buckets = [];

        for ($hourOffset = 0; $hourOffset < $effectiveWindowHours; $hourOffset++) {
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
            $query = Order::query()
                ->select(['created_at', 'amount']);

            $this->applyDateRangeToOrderQuery($query, [
                'start_at' => $startAt,
                'end_at' => $endAt,
            ]);

            $orders = $query->get();

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
            $query = Order::query()
                ->selectRaw($hourBucketExpression . ' as hour_bucket')
                ->selectRaw('COUNT(*) as order_count')
                ->selectRaw('COALESCE(SUM(amount), 0) as total_amount');

            $this->applyDateRangeToOrderQuery($query, [
                'start_at' => $startAt,
                'end_at' => $endAt,
            ]);

            $rows = $query
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
            'window_hours' => $effectiveWindowHours,
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

    private function buildMonthlyOrderAnalytics(int $windowMonths, array $dateRange = []): array
    {
        $windowMonths = max(1, $windowMonths);

        if ($this->hasDateRangeFilter($dateRange)) {
            $filterStartAt = ($dateRange['start_at'] ?? Carbon::now())->copy();
            $filterEndAt = ($dateRange['end_at'] ?? Carbon::now())->copy();
            $startAt = $filterStartAt->copy()->startOfMonth();
            $endAt = $filterEndAt->copy()->endOfMonth();
            $windowMonths = max(1, $startAt->diffInMonths($endAt) + 1);
        } else {
            $startAt = Carbon::now()->startOfMonth()->subMonths($windowMonths - 1);
            $endAt = Carbon::now()->endOfMonth();
            $filterStartAt = $startAt->copy();
            $filterEndAt = $endAt->copy();
        }

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
            $query = Order::query()
                ->select(['created_at', 'amount']);

            $this->applyDateRangeToOrderQuery($query, [
                'start_at' => $filterStartAt,
                'end_at' => $filterEndAt,
            ]);

            $orders = $query->get();

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
            $query = Order::query()
                ->selectRaw($monthBucketExpression . ' as month_bucket')
                ->selectRaw('COUNT(*) as order_count')
                ->selectRaw('COALESCE(SUM(amount), 0) as total_amount');

            $this->applyDateRangeToOrderQuery($query, [
                'start_at' => $filterStartAt,
                'end_at' => $filterEndAt,
            ]);

            $rows = $query
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

    private function resolveOrderDateRange(Request $request): array
    {
        $startDate = trim((string) $request->query('start_date', ''));
        $endDate = trim((string) $request->query('end_date', ''));

        $startAt = $this->parseDashboardDate($startDate);
        $endAt = $this->parseDashboardDate($endDate, true);

        if ($startAt && $endAt && $startAt->gt($endAt)) {
            [$startAt, $endAt] = [$endAt->copy()->startOfDay(), $startAt->copy()->endOfDay()];
            $startDate = $startAt->format('Y-m-d');
            $endDate = $endAt->format('Y-m-d');
        }

        return [
            'start_date' => $startDate !== '' ? $startDate : null,
            'end_date' => $endDate !== '' ? $endDate : null,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    private function parseDashboardDate(string $value, bool $endOfDay = false): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function hasDateRangeFilter(array $dateRange): bool
    {
        return ($dateRange['start_at'] ?? null) instanceof Carbon
            || ($dateRange['end_at'] ?? null) instanceof Carbon;
    }

    private function applyDateRangeToOrderQuery(Builder $query, array $dateRange, string $column = 'created_at'): Builder
    {
        /** @var Carbon|null $startAt */
        $startAt = $dateRange['start_at'] ?? null;
        /** @var Carbon|null $endAt */
        $endAt = $dateRange['end_at'] ?? null;

        if ($startAt && $endAt) {
            return $query->whereBetween($column, [$startAt, $endAt]);
        }

        if ($startAt) {
            return $query->where($column, '>=', $startAt);
        }

        if ($endAt) {
            return $query->where($column, '<=', $endAt);
        }

        return $query;
    }

    private function resolveHourlyWindowBounds(int $windowHours, array $dateRange): array
    {
        $now = Carbon::now();

        if (!$this->hasDateRangeFilter($dateRange)) {
            $endAt = $now->copy();
            $startAt = $endAt->copy()->subHours($windowHours - 1)->startOfHour();

            return [$startAt, $endAt, $windowHours];
        }

        $endAt = ($dateRange['end_at'] ?? $now)->copy();
        if ($endAt->gt($now)) {
            $endAt = $now->copy();
        }

        $startAt = ($dateRange['start_at'] ?? $endAt->copy()->subHours($windowHours - 1))->copy()
            ->startOfHour();

        $maxWindowStart = $endAt->copy()->subHours($windowHours - 1)->startOfHour();
        if ($startAt->lt($maxWindowStart)) {
            $startAt = $maxWindowStart;
        }

        if ($startAt->gt($endAt)) {
            $startAt = $endAt->copy()->startOfHour();
        }

        $effectiveWindowHours = max(1, $startAt->diffInHours($endAt) + 1);

        return [$startAt, $endAt, $effectiveWindowHours];
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
