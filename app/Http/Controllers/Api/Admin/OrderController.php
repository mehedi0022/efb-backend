<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderDetails;
use App\Models\Customer;
use App\Models\Courierapi;
use App\Models\District;
use App\Models\GeneralSetting;
use App\Models\Contact;
use App\Models\Product;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Shipping;
use App\Models\ShippingCharge;
use App\Models\User;
use App\Services\ApiClient;
use App\Services\OrderStatusService;
use App\Services\SteadfastCourierService;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderController extends Controller
{
    /**
     * Cache resolved product SKUs while processing dropshipping payloads.
     *
     * @var array<int, string>
     */
    private array $dropshippingProductSkuCache = [];

    private const PATHAO_DEFAULT_BASE_URL = 'https://api-hermes.pathao.com';
    private const PATHAO_ISSUE_TOKEN_PATH = '/aladdin/api/v1/issue-token';
    private const PATHAO_CREATE_ORDER_PATH = '/aladdin/api/v1/orders';
    private const PATHAO_STORES_PATH = '/aladdin/api/v1/stores';
    private const PATHAO_ORDER_STATUS_PATH = '/aladdin/api/v1/orders/%s';

    public function __construct(private readonly OrderStatusService $orderStatusService)
    {
    }

    /**
     * Get orders by status
     */
    public function index(Request $request, $status = 'all')
    {
        try {
            $this->orderStatusService->ensureDefaultStatuses();

            $query = Order::with([
                'customer:id,name,phone,address',
                'status:id,name,slug',
                'shipping:id,order_id,name,phone,address,area,ip_address',
                'user:id,name',
                'orderdetails:id,order_id,image,product_name,qty,sale_price',
            ]);

            // Filter by status
            if ($status !== 'all') {
                $filter = $this->orderStatusService->filterValuesForRoute((string) $status);
                $statusIds = $filter['ids'] ?? [];
                $rawValues = $filter['raw_values'] ?? [];

                $query->where(function ($statusQuery) use ($statusIds, $rawValues) {
                    if (!empty($statusIds)) {
                        $statusQuery->whereIn('order_status', $statusIds);
                    }

                    if (!empty($rawValues)) {
                        if (!empty($statusIds)) {
                            $statusQuery->orWhereIn(DB::raw('LOWER(TRIM(order_status))'), $rawValues);
                        } else {
                            $statusQuery->whereIn(DB::raw('LOWER(TRIM(order_status))'), $rawValues);
                        }
                    }
                });
            }

          

            if ($request->filled('tracking_code') && Schema::hasColumn('orders', 'courier_order_id')) {
                $trackingCode = trim((string) $request->input('tracking_code'));
                $query->where('courier_order_id', 'LIKE', "%{$trackingCode}%");
            }

            // Search by keyword
            if ($request->filled('keyword')) {
                $keyword = $request->keyword;
                $query->where(function($q) use ($keyword) {
                    $q->where('invoice_id', 'LIKE', "%{$keyword}%")
                      ->orWhere('ip_address', 'LIKE', "%{$keyword}%")
                      ->orWhereHas('customer', function($customerQuery) use ($keyword) {
                          $customerQuery->where('name', 'LIKE', "%{$keyword}%")
                                      ->orWhere('phone', 'LIKE', "%{$keyword}%");
                      })
                      ->orWhereHas('shipping', function($shippingQuery) use ($keyword) {
                          $shippingQuery->where('name', 'LIKE', "%{$keyword}%")
                                        ->orWhere('phone', 'LIKE', "%{$keyword}%");
                      });
                });
            }

            // Filter by date range (updated_at)
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('updated_at', [
                    $request->start_date . ' 00:00:00',
                    $request->end_date . ' 23:59:59'
                ]);
            } elseif ($request->filled('start_date')) {
                $query->whereDate('updated_at', '>=', $request->start_date);
            } elseif ($request->filled('end_date')) {
                $query->whereDate('updated_at', '<=', $request->end_date);
            }

            // Pagination
            $perPage = max(1, min((int) $request->get('per_page', 20), 100));
            $orders = $query->latest()->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $orders->map(function($order) {
                    return $this->formatOrder($order);
                }),
                'pagination' => [
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching orders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Courier-wise order list
     */
    public function courierOrders(Request $request)
    {
        $validated = $request->validate([
            'courier' => 'nullable|string|in:pathao,steadfast',
            'status' => 'nullable|string|max:60',
            'name' => 'nullable|string|max:155',
            'tracking_code' => 'nullable|string|max:120',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if (!Schema::hasColumn('orders', 'courier_name')) {
            return response()->json([
                'success' => true,
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => (int) ($validated['per_page'] ?? 20),
                    'current_page' => 1,
                    'last_page' => 1,
                    'from' => null,
                    'to' => null,
                ],
            ]);
        }

        try {
            $query = Order::with([
                'customer:id,name,phone,address',
                'status:id,name,slug',
                'shipping:id,order_id,name,phone,address,area,ip_address',
                'user:id,name',
                'orderdetails:id,order_id,image,product_name,qty,sale_price',
            ])
                ->whereNotNull('courier_name')
                ->where('courier_name', '!=', '');

            if (!empty($validated['courier'])) {
                $query->where('courier_name', strtolower(trim((string) $validated['courier'])));
            }

            if (!empty($validated['status']) && Schema::hasColumn('orders', 'courier_status')) {
                $query->whereRaw('LOWER(TRIM(courier_status)) = ?', [
                    strtolower(trim((string) $validated['status'])),
                ]);
            }

            if (!empty($validated['name'])) {
                $name = trim((string) $validated['name']);
                $query->where(function ($nameQuery) use ($name) {
                    $nameQuery->whereHas('customer', function ($customerQuery) use ($name) {
                        $customerQuery->where('name', 'LIKE', "%{$name}%");
                    })->orWhereHas('shipping', function ($shippingQuery) use ($name) {
                        $shippingQuery->where('name', 'LIKE', "%{$name}%");
                    });
                });
            }

            if (!empty($validated['tracking_code']) && Schema::hasColumn('orders', 'courier_order_id')) {
                $trackingCode = trim((string) $validated['tracking_code']);
                $query->where('courier_order_id', 'LIKE', "%{$trackingCode}%");
            }

            $dateColumn = Schema::hasColumn('orders', 'courier_synced_at')
                ? 'courier_synced_at'
                : 'updated_at';

            if (!empty($validated['start_date']) && !empty($validated['end_date'])) {
                $query->whereBetween($dateColumn, [
                    $validated['start_date'] . ' 00:00:00',
                    $validated['end_date'] . ' 23:59:59',
                ]);
            } elseif (!empty($validated['start_date'])) {
                $query->whereDate($dateColumn, '>=', $validated['start_date']);
            } elseif (!empty($validated['end_date'])) {
                $query->whereDate($dateColumn, '<=', $validated['end_date']);
            }

            $perPage = max(1, min((int) ($validated['per_page'] ?? 20), 100));
            $orders = $query->latest($dateColumn)->latest('id')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $orders->map(fn ($order) => $this->formatOrder($order)),
                'pagination' => [
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching courier orders: ' . $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Synchronize courier status for sent orders.
     */
    public function syncCourierStatus(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'nullable|array|min:1',
            'order_ids.*' => 'required|integer|exists:orders,id',
        ]);

        if (!Schema::hasColumn('orders', 'courier_name')) {
            return response()->json([
                'success' => false,
                'message' => 'Courier tracking columns are missing. Please run migrations.',
            ], 422);
        }

        $query = Order::query()
            ->whereNotNull('courier_name')
            ->where('courier_name', '!=', '');

        if (!empty($validated['order_ids'])) {
            $query->whereIn('id', $validated['order_ids']);
        }

        $orders = $query->get();
        if ($orders->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No courier orders found for synchronization.',
                'synced_orders' => [],
            ]);
        }

        $pathao = Courierapi::query()->where('type', 'pathao')->first();
        $syncedOrders = [];
        $failedOrders = [];

        foreach ($orders as $order) {
            $courierName = strtolower(trim((string) ($order->courier_name ?? '')));
            if ($courierName === '') {
                continue;
            }

            try {
                $nextStatus = trim((string) ($order->courier_status ?? 'synced'));
                $error = null;

                if ($courierName === 'pathao' && $pathao) {
                    $statusFromApi = $this->fetchPathaoStatus($order, $pathao);
                    if ($statusFromApi !== null && $statusFromApi !== '') {
                        $nextStatus = $statusFromApi;
                    }
                }

                $this->recordCourierDispatch(
                    $order,
                    $courierName,
                    $nextStatus !== '' ? $nextStatus : 'synced',
                    trim((string) ($order->courier_order_id ?? '')) ?: null,
                    $error
                );

                $syncedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'courier' => $courierName,
                    'courier_status' => $nextStatus,
                ];
            } catch (\Throwable $exception) {
                $error = trim((string) $exception->getMessage()) ?: 'Status sync failed.';
                $this->recordCourierDispatch(
                    $order,
                    $courierName,
                    'sync_failed',
                    trim((string) ($order->courier_order_id ?? '')) ?: null,
                    $error
                );

                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $error,
                ];
            }
        }

        if (!empty($failedOrders)) {
            return response()->json([
                'success' => false,
                'message' => $failedOrders[0]['message'] ?? 'Some courier statuses could not be synchronized.',
                'synced_orders' => $syncedOrders,
                'failed_orders' => $failedOrders,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Courier statuses synchronized successfully.',
            'synced_orders' => $syncedOrders,
        ]);
    }

    /**
     * Get single order details
     */
    public function show($id)
    {
        try {
            $this->orderStatusService->ensureDefaultStatuses();

            $order = Order::with([
                'customer:id,name,phone,address',
                'status:id,name,slug',
                'orderdetails:id,order_id,product_name,qty,sale_price,image',
                'shipping:id,order_id,name,phone,address,area,ip_address',
                'user:id,name',
            ])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->formatOrder($order, true),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'required|integer|exists:orders,id',
            'status' => 'required',
        ]);

        try {
            $this->orderStatusService->ensureDefaultStatuses();
            $statusId = $this->orderStatusService->resolveStatusId($request->input('status'));

            if (!$statusId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid order status selected.',
                ], 422);
            }

            $this->applyOrderStatusUpdate($request->order_ids, $statusId);

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'status_id' => $statusId,
            ]);
        } catch (ValidationException $validationException) {
            return response()->json([
                'success' => false,
                'message' => 'Order status update validation failed.',
                'errors' => $validationException->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a manual order from admin panel
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'shipping_name' => 'required|string|max:155',
            'shipping_phone' => 'required|string|max:55',
            'shipping_email' => 'nullable|email|max:55',
            'shipping_address' => 'required|string|max:256',
            'shipping_area' => 'nullable|string|max:100',
            'shipping_charge_id' => 'nullable|integer|exists:shipping_charges,id',
            'shipping_charge' => 'nullable|integer|min:0',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|string|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
            'order_status' => 'nullable',
            'note' => 'nullable|string',
            'admin_note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer|exists:products,id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.sale_price' => 'required|numeric|min:0',
            'items.*.purchase_price' => 'nullable|numeric|min:0',
            'items.*.image' => 'nullable|string|max:255',
        ]);

        try {
            $this->orderStatusService->ensureDefaultStatuses();
            $defaultStatusId = $this->orderStatusService->resolveStatusId('new-order') ?: 1;
            $resolvedStatusId = $defaultStatusId;

            if (
                array_key_exists('order_status', $validated)
                && $validated['order_status'] !== null
                && trim((string) $validated['order_status']) !== ''
            ) {
                $resolvedStatusId = $this->orderStatusService->resolveStatusId($validated['order_status']);
                if (!$resolvedStatusId) {
                    throw ValidationException::withMessages([
                        'order_status' => ['Invalid order status selected.'],
                    ]);
                }
            }

            return DB::transaction(function () use ($validated, $request, $resolvedStatusId) {
                $shippingChargeRecord = null;
                if (
                    array_key_exists('shipping_charge_id', $validated)
                    && $validated['shipping_charge_id'] !== null
                ) {
                    $shippingChargeRecord = ShippingCharge::query()->find((int) $validated['shipping_charge_id']);
                }

                $shippingCharge = array_key_exists('shipping_charge', $validated)
                    ? max(0, (int) $validated['shipping_charge'])
                    : max(0, (int) ($shippingChargeRecord?->amount ?? 0));
                [$discountType, $discountValue] = $this->resolveDiscountInput($validated);

                $shippingName = trim((string) $validated['shipping_name']);
                $shippingPhone = trim((string) $validated['shipping_phone']);
                $shippingAddress = trim((string) $validated['shipping_address']);
                $shippingEmail = trim((string) ($validated['shipping_email'] ?? ''));
                $shippingArea = trim((string) (
                    $validated['shipping_area']
                    ?? $shippingChargeRecord?->name
                    ?? ''
                ));

                $customer = Customer::query()->where('phone', $shippingPhone)->first();
                if (!$customer) {
                    $customer = Customer::create([
                        'name' => $shippingName,
                        'slug' => Str::slug($shippingName ?: ('customer-' . $shippingPhone)),
                        'phone' => $shippingPhone,
                        'email' => $shippingEmail !== '' ? $shippingEmail : 'manual-' . time() . '@example.com',
                        'password' => bcrypt((string) random_int(100000, 999999)),
                        'ip_address' => $request->ip(),
                        'verify' => 1,
                        'status' => 'active',
                        'address' => $shippingAddress,
                        'area' => $shippingArea !== '' ? $shippingArea : null,
                    ]);
                } else {
                    $customerUpdates = [];
                    if ($shippingName !== '' && trim((string) ($customer->name ?? '')) !== $shippingName) {
                        $customerUpdates['name'] = $shippingName;
                    }
                    if ($shippingAddress !== '' && trim((string) ($customer->address ?? '')) !== $shippingAddress) {
                        $customerUpdates['address'] = $shippingAddress;
                    }
                    if ($shippingArea !== '' && trim((string) ($customer->area ?? '')) !== $shippingArea) {
                        $customerUpdates['area'] = $shippingArea;
                    }
                    if ($shippingEmail !== '' && trim((string) ($customer->email ?? '')) === '') {
                        $customerUpdates['email'] = $shippingEmail;
                    }
                    if (!empty($customerUpdates)) {
                        $customer->update($customerUpdates);
                    }
                }

                $order = Order::create([
                    'invoice_id' => $this->generateInvoiceId(),
                    'amount' => 0,
                    'discount' => 0,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'shipping_charge' => $shippingCharge,
                    'customer_id' => (int) $customer->id,
                    'order_status' => (string) $resolvedStatusId,
                    'note' => $validated['note'] ?? null,
                    'admin_note' => $validated['admin_note'] ?? null,
                    'ip_address' => $request->ip(),
                ]);

                $finalItems = $this->syncOrderItems($order, $validated['items'] ?? []);
                $nextProductQtyMap = $this->buildProductQtyMapFromDetails($finalItems);
                $this->reconcileOrderStockByDifference(
                    '',
                    (string) $resolvedStatusId,
                    [],
                    $nextProductQtyMap
                );

                Shipping::create([
                    'order_id' => (int) $order->id,
                    'customer_id' => (int) $customer->id,
                    'name' => $shippingName,
                    'phone' => $shippingPhone,
                    'address' => $shippingAddress,
                    'area' => $shippingArea,
                    'ip_address' => $request->ip(),
                ]);

                $subTotal = $this->calculateOrderSubTotal((int) $order->id);
                $discountAmount = $this->calculateDiscountAmount($subTotal, $discountType, $discountValue);
                $order->discount = $discountAmount;
                $order->discount_type = $discountType;
                $order->discount_value = $discountValue;
                $order->amount = max(0, $subTotal + $shippingCharge - $discountAmount);
                $order->save();

                Payment::create([
                    'order_id' => (int) $order->id,
                    'customer_id' => (int) $customer->id,
                    'amount' => (int) $order->amount,
                    'payment_method' => 'Cash On Delivery',
                    'payment_status' => 'pending',
                ]);

                $freshOrder = Order::with([
                    'customer:id,name,phone,address',
                    'status:id,name,slug',
                    'shipping:id,order_id,name,phone,address,area,ip_address',
                    'user:id,name',
                    'orderdetails:id,order_id,image,product_name,qty,sale_price',
                ])->findOrFail($order->id);

                return response()->json([
                    'success' => true,
                    'message' => 'Order created successfully.',
                    'data' => $this->formatOrder($freshOrder),
                ], 201);
            });
        } catch (ValidationException $validationException) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed while creating order.',
                'errors' => $validationException->errors(),
            ], 422);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating order: ' . $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Update order by invoice ID (admin edit page)
     */
    public function updateByInvoice(Request $request, string $invoiceId)
    {
        $validated = $request->validate([
            'shipping_name' => 'required|string|max:155',
            'shipping_phone' => 'required|string|max:55',
            'shipping_address' => 'required|string|max:256',
            'shipping_area' => 'nullable|string|max:100',
            'shipping_charge_id' => 'nullable|integer|exists:shipping_charges,id',
            'shipping_charge' => 'nullable|integer|min:0',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|string|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
            'order_status' => 'nullable',
            'note' => 'nullable|string',
            'admin_note' => 'nullable|string',
            'items' => 'nullable|array|min:1',
            'items.*.id' => 'nullable|integer',
            'items.*.product_id' => 'nullable|integer|exists:products,id',
            'items.*.product_name' => 'nullable|string|max:255',
            'items.*.qty' => 'required_with:items|integer|min:1',
            'items.*.sale_price' => 'required_with:items|numeric|min:0',
            'items.*.purchase_price' => 'nullable|numeric|min:0',
            'items.*.image' => 'nullable|string|max:255',
        ]);

        try {
            $this->orderStatusService->ensureDefaultStatuses();

            $order = Order::with(['shipping', 'orderdetails', 'status', 'customer', 'user'])
                ->where('invoice_id', $invoiceId)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            DB::beginTransaction();

            $previousStatusValue = (string) $order->order_status;
            $nextStatusValue = $previousStatusValue;

            if (
                array_key_exists('order_status', $validated) &&
                $validated['order_status'] !== null &&
                trim((string) $validated['order_status']) !== ''
            ) {
                $resolvedStatusId = $this->orderStatusService->resolveStatusId($validated['order_status']);
                if (!$resolvedStatusId) {
                    throw ValidationException::withMessages([
                        'order_status' => ['Invalid order status selected.'],
                    ]);
                }

                $nextStatusValue = (string) $resolvedStatusId;
                $order->order_status = $nextStatusValue;
                $order->save();
            }

            $beforeItems = $order->orderdetails()->get();
            $previousProductQtyMap = $this->buildProductQtyMapFromDetails($beforeItems);

            $finalItems = $beforeItems;
            if (array_key_exists('items', $validated)) {
                $finalItems = $this->syncOrderItems($order, $validated['items'] ?? []);
            }
            $nextProductQtyMap = $this->buildProductQtyMapFromDetails($finalItems);

            $this->reconcileOrderStockByDifference(
                $previousStatusValue,
                $nextStatusValue,
                $previousProductQtyMap,
                $nextProductQtyMap
            );

            $selectedShippingCharge = null;
            if (
                array_key_exists('shipping_charge_id', $validated)
                && $validated['shipping_charge_id'] !== null
            ) {
                $selectedShippingCharge = ShippingCharge::query()->find((int) $validated['shipping_charge_id']);
            }

            $shipping = Shipping::firstOrNew(['order_id' => $order->id]);
            if (!$shipping->exists) {
                $shipping->customer_id = $order->customer_id;
                $shipping->ip_address = $request->ip();
            }

            $shipping->name = $validated['shipping_name'];
            $shipping->phone = $validated['shipping_phone'];
            $shipping->address = $validated['shipping_address'];
            $shipping->area = trim((string) (
                $validated['shipping_area']
                ?? $selectedShippingCharge?->name
                ?? $shipping->area
                ?? ''
            ));
            $shipping->save();

            if (array_key_exists('shipping_charge', $validated)) {
                $order->shipping_charge = (int) $validated['shipping_charge'];
            } elseif ($selectedShippingCharge) {
                $order->shipping_charge = (int) $selectedShippingCharge->amount;
            }
            if (array_key_exists('note', $validated)) {
                $order->note = $validated['note'];
            }
            if (array_key_exists('admin_note', $validated)) {
                $order->admin_note = $validated['admin_note'];
            }

            $subTotal = $this->calculateOrderSubTotal($order->id);
            $existingDiscountType = strtolower(trim((string) ($order->discount_type ?? 'fixed')));
            if (!in_array($existingDiscountType, ['fixed', 'percentage'], true)) {
                $existingDiscountType = 'fixed';
            }

            $existingDiscountValue = (float) ($order->discount_value ?? $order->discount ?? 0);
            $hasDiscountInput = array_key_exists('discount', $validated)
                || array_key_exists('discount_value', $validated)
                || array_key_exists('discount_type', $validated);
            if ($hasDiscountInput) {
                [$existingDiscountType, $existingDiscountValue] = $this->resolveDiscountInput(
                    $validated,
                    $existingDiscountType,
                    $existingDiscountValue
                );
            }

            $order->discount_type = $existingDiscountType;
            $order->discount_value = $existingDiscountValue;
            $order->discount = $this->calculateDiscountAmount(
                $subTotal,
                $existingDiscountType,
                $existingDiscountValue
            );
            $order->amount = max(
                0,
                $subTotal + (int) $order->shipping_charge - (int) $order->discount
            );
            $order->save();

            DB::commit();

            $freshOrder = Order::with([
                'customer:id,name,phone,address',
                'status:id,name,slug',
                'shipping:id,order_id,name,phone,address,area,ip_address',
                'user:id,name',
                'orderdetails:id,order_id,image,product_name,qty,sale_price',
            ])->findOrFail($order->id);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $this->formatOrder($freshOrder),
            ]);
        } catch (ValidationException $validationException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'Validation failed while updating order.',
                'errors' => $validationException->errors(),
            ], 422);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'Error updating order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign user to orders
     */
    public function assignUser(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array',
            'user_id' => 'required|exists:users,id',
        ]);

        try {
            Order::whereIn('id', $request->order_ids)
                ->update(['user_id' => $request->user_id]);

            return response()->json([
                'success' => true,
                'message' => 'User assigned successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error assigning user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete orders
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'required|integer|exists:orders,id',
        ]);

        try {
            foreach ($request->order_ids as $orderId) {
                OrderDetails::where('order_id', $orderId)->delete();
                Shipping::where('order_id', $orderId)->delete();
                Payment::where('order_id', $orderId)->delete();
                Order::where('id', $orderId)->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Orders deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting orders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get order statistics
     */
    public function statistics()
    {
        try {
            $this->orderStatusService->ensureDefaultStatuses();

            $stats = [
                'total' => Order::count(),
                'new_order' => $this->countOrdersByCanonicalStatus('new_order'),
                'complete' => $this->countOrdersByCanonicalStatus('complete'),
                'no_response' => $this->countOrdersByCanonicalStatus('no_response'),
                'hold' => $this->countOrdersByCanonicalStatus('hold'),
                'cancel' => $this->countOrdersByCanonicalStatus('cancel'),
                'fb_sent' => $this->countOrdersByCanonicalStatus('fb_sent'),
                'today' => Order::whereDate('created_at', Carbon::today())->count(),
                'this_month' => Order::whereMonth('created_at', Carbon::now()->month)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark orders as sent to EFB
     */
    public function sendToSteadfast(Request $request)
    {
        if (!Str::contains($request->path(), 'send-dropshipping')) {
            return $this->dispatchToSteadfastCourier($request, app(SteadfastCourierService::class));
        }

        $validated = $request->validate([
            'order_id' => 'nullable|integer|exists:orders,id',
            'order_ids' => 'nullable|array|min:1',
            'order_ids.*' => 'required|integer|exists:orders,id',
        ]);

        $orderIds = collect($validated['order_ids'] ?? [])
            ->when(isset($validated['order_id']), fn ($collection) => $collection->push($validated['order_id']))
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($orderIds)) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'No valid order selected.',
            ], 422);
        }

        $canMarkCompleteOrder = Schema::hasColumn('orders', 'is_complete_order');
        $fbSentStatusId = $this->orderStatusService->resolveStatusId('fb-sent');

        $sellerCode = $this->getSellerCode($request);
        if (!$sellerCode) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Seller code not found. Please configure it in users.seller_code or EFB_SELLER_CODE.',
            ], 422);
        }

        $baseUrl = rtrim((string) config('services.api.base_url'), '/');
        $endpoint = ($baseUrl !== '' ? $baseUrl : 'http://api.freelancerbangladesh.com') . '/orders/receive';

        $sentOrders = [];
        $failedOrders = [];

        foreach ($orderIds as $orderId) {
            $order = Order::with(['shipping', 'customer', 'orderdetails.product:id,product_code'])->find($orderId);
            if (!$order) {
                $failedOrders[] = [
                    'order_id' => $orderId,
                    'message' => 'Order not found.',
                ];
                continue;
            }

            $this->recalculateOrderFinancials($order);

            $payload = $this->buildDropshippingPayload($order, $sellerCode);
            $itemErrors = $payload['item_errors'] ?? [];
            unset($payload['item_errors']);

            if (!empty($itemErrors)) {
                $failedMessage = $itemErrors[0];
                $this->recordCourierDispatch($order, 'steadfast', 'failed', null, $failedMessage);
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $failedMessage,
                ];
                continue;
            }

            if (empty($payload['items'])) {
                $failedMessage = 'No order items found for this order.';
                $this->recordCourierDispatch($order, 'steadfast', 'failed', null, $failedMessage);
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $failedMessage,
                ];
                continue;
            }

            try {
                $response = app(ApiClient::class)
                    ->request()
                    ->post($endpoint, $payload);
            } catch (\Throwable $exception) {
                $failedMessage = $this->normalizeDropshippingExceptionMessage((string) $exception->getMessage());

                Log::error('Dropshipping request failed.', [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $exception->getMessage(),
                ]);
                $this->recordCourierDispatch($order, 'steadfast', 'failed', null, $failedMessage);

                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $failedMessage,
                ];
                continue;
            }

            if ($response->successful()) {
                if ($canMarkCompleteOrder) {
                    $order->is_complete_order = 1;
                }

                if ($fbSentStatusId !== null) {
                    $order->order_status = (string) $fbSentStatusId;
                }
                $order->save();

                $courierOrderId = $this->extractCourierOrderIdFromResponse($response);
                $this->recordCourierDispatch(
                    $order,
                    'steadfast',
                    'sent',
                    $courierOrderId,
                    null
                );

                $sentOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'courier_order_id' => $courierOrderId,
                ];
                continue;
            }

            $failedMessage = $response->json('message');
            if (!is_string($failedMessage) || trim($failedMessage) === '') {
                $failedMessage = trim((string) $response->body()) ?: 'Dropshipping request failed.';
            }
            $this->recordCourierDispatch($order, 'steadfast', 'failed', null, $failedMessage);

            $failedOrders[] = [
                'order_id' => (int) $order->id,
                'invoice_id' => $order->invoice_id,
                'message' => $failedMessage,
            ];
        }

        if (!empty($failedOrders)) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => $failedOrders[0]['message'] ?? 'Failed to send order(s) to dropshipping.',
                'sent_orders' => $sentOrders,
                'failed_orders' => $failedOrders,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => count($sentOrders) === 1
                ? 'Order sent to dropshipping successfully.'
                : 'Orders sent to dropshipping successfully.',
            'sent_orders' => $sentOrders,
        ]);
    }

    /**
     * Send orders to Pathao.
     */
    public function sendToPathao(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'required|integer|exists:orders,id',
            'refresh_token' => 'nullable|boolean',
            'store_id' => 'nullable|string|max:120',
            'recipient_city' => 'nullable|integer|min:1',
            'recipient_zone' => 'nullable|integer|min:1',
            'recipient_area' => 'nullable|integer|min:1',
            'delivery_type' => 'nullable|integer|min:1',
            'item_type' => 'nullable|integer|min:1',
            'item_weight' => 'nullable|numeric|min:0.1|max:30',
        ]);

        /** @var Courierapi|null $pathao */
        $pathao = Courierapi::query()
            ->where('type', 'pathao')
            ->first();

        if (!$pathao) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Pathao configuration not found. Please configure Pathao first.',
            ], 422);
        }

        if ((int) ($pathao->status ?? 0) !== 1) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Pathao integration is inactive. Activate it from courier settings.',
            ], 422);
        }

        $endpoint = $this->resolvePathaoCreateOrderEndpoint((string) ($pathao->url ?? ''));

        try {
            $pathaoToken = $this->resolvePathaoAccessToken($pathao, $request->boolean('refresh_token'));
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => collect($exception->errors())->flatten()->first() ?: 'Pathao token generation failed.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $storeId = $this->resolvePathaoStoreId(
            $pathao,
            $pathaoToken,
            $validated['store_id'] ?? null
        );

        $payloadOverrides = [
            'recipient_city' => $validated['recipient_city'] ?? null,
            'recipient_zone' => $validated['recipient_zone'] ?? null,
            'recipient_area' => $validated['recipient_area'] ?? null,
            'delivery_type' => $validated['delivery_type'] ?? null,
            'item_type' => $validated['item_type'] ?? null,
            'item_weight' => $validated['item_weight'] ?? null,
        ];

        $sentOrders = [];
        $failedOrders = [];

        foreach ($validated['order_ids'] as $orderId) {
            $order = Order::with(['shipping', 'customer', 'orderdetails'])->find((int) $orderId);
            if (!$order) {
                $failedOrders[] = [
                    'order_id' => (int) $orderId,
                    'message' => 'Order not found.',
                ];
                continue;
            }

            $this->recalculateOrderFinancials($order);

            if ($this->isOrderAlreadyTakenByCourier($order)) {
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $this->buildOrderAlreadyTakenMessage($order),
                ];
                continue;
            }

            if (!$this->isCompletedOrderForCourierDispatch($order)) {
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => 'Only completed orders can be sent to Pathao.',
                ];
                continue;
            }

            $payload = $this->buildPathaoPayload(
                $order,
                $payloadOverrides,
                $storeId
            );

            try {
                $response = $this->dispatchPathaoOrderRequest(
                    $endpoint,
                    $pathaoToken,
                    $payload,
                    $storeId
                );

                if ($response->status() === 401) {
                    $pathaoToken = $this->resolvePathaoAccessToken($pathao, true);
                    if ($storeId === null) {
                        $storeId = $this->resolvePathaoStoreId($pathao, $pathaoToken, $validated['store_id'] ?? null);
                    }
                    $response = $this->dispatchPathaoOrderRequest(
                        $endpoint,
                        $pathaoToken,
                        $payload,
                        $storeId
                    );
                }
            } catch (Throwable $exception) {
                $failedMessage = trim((string) $exception->getMessage()) ?: 'Pathao request failed.';
                $this->recordCourierDispatch($order, 'pathao', 'failed', null, $failedMessage);
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $failedMessage,
                ];
                continue;
            }

            if ($response->successful()) {
                $courierOrderId = $this->extractCourierOrderIdFromResponse($response);
                $this->recordCourierDispatch(
                    $order,
                    'pathao',
                    'sent',
                    $courierOrderId,
                    null
                );

                $sentOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'courier_order_id' => $courierOrderId,
                ];
                continue;
            }

            $failedMessage = $this->resolveCourierErrorMessage($response, 'Pathao order request failed.');
            $this->recordCourierDispatch($order, 'pathao', 'failed', null, $failedMessage);
            $failedOrders[] = [
                'order_id' => (int) $order->id,
                'invoice_id' => $order->invoice_id,
                'message' => $failedMessage,
            ];
        }

        if (!empty($failedOrders)) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => $failedOrders[0]['message'] ?? 'Failed to send order(s) to Pathao.',
                'sent_orders' => $sentOrders,
                'failed_orders' => $failedOrders,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => count($sentOrders) === 1
                ? 'Order sent to Pathao successfully.'
                : 'Orders sent to Pathao successfully.',
            'sent_orders' => $sentOrders,
        ]);
    }

    /**
     * Get Pathao meta data (stores + cities + defaults) for dispatch modal.
     */
    public function pathaoMeta(Request $request)
    {
        /** @var Courierapi|null $pathao */
        $pathao = Courierapi::query()
            ->where('type', 'pathao')
            ->first();

        if (!$pathao) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Pathao configuration not found. Please configure Pathao first.',
            ], 422);
        }

        if ((int) ($pathao->status ?? 0) !== 1) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Pathao integration is inactive. Activate it from courier settings.',
            ], 422);
        }

        try {
            $token = $this->resolvePathaoAccessToken($pathao, $request->boolean('refresh_token'));
            $stores = [];
            try {
                $stores = $this->fetchPathaoStoreOptionsFromApi($pathao, $token);
            } catch (Throwable $exception) {
                $stores = [];
            }
            $cities = $this->fetchPathaoCityOptionsFromApi($pathao, $token);

            $resolvedStoreId = $this->resolvePathaoStoreId($pathao, $token, null);
            if ($resolvedStoreId !== null) {
                $storeExists = collect($stores)->contains(fn ($store) => (string) ($store['id'] ?? '') === (string) $resolvedStoreId);
                if (!$storeExists) {
                    $stores[] = [
                        'id' => $this->normalizePathaoLocationValue($resolvedStoreId),
                        'name' => 'Store ' . $resolvedStoreId,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'status' => 'success',
                'data' => [
                    'stores' => $stores,
                    'cities' => $cities,
                    'defaults' => [
                        'store_id' => $resolvedStoreId,
                        'recipient_city' => $this->normalizePathaoLocationValue(config('services.pathao.default_city_id')),
                        'recipient_zone' => $this->normalizePathaoLocationValue(config('services.pathao.default_zone_id')),
                        'recipient_area' => $this->normalizePathaoLocationValue(config('services.pathao.default_area_id')),
                    ],
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to load Pathao location data.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Unable to load Pathao location data right now.',
            ], 422);
        }
    }

    /**
     * Get Pathao zones by city.
     */
    public function pathaoZones(Request $request)
    {
        $validated = $request->validate([
            'city_id' => 'required|integer|min:1',
            'refresh_token' => 'nullable|boolean',
        ]);

        /** @var Courierapi|null $pathao */
        $pathao = Courierapi::query()
            ->where('type', 'pathao')
            ->first();

        if (!$pathao) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Pathao configuration not found. Please configure Pathao first.',
            ], 422);
        }

        try {
            $token = $this->resolvePathaoAccessToken($pathao, $request->boolean('refresh_token'));
            $zones = $this->fetchPathaoZoneOptionsFromApi($pathao, $token, (int) $validated['city_id']);

            return response()->json([
                'success' => true,
                'status' => 'success',
                'data' => [
                    'city_id' => (int) $validated['city_id'],
                    'zones' => $zones,
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to load Pathao zones.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Unable to load Pathao zones right now.',
            ], 422);
        }
    }

    /**
     * Get Pathao areas by zone.
     */
    public function pathaoAreas(Request $request)
    {
        $validated = $request->validate([
            'zone_id' => 'required|integer|min:1',
            'refresh_token' => 'nullable|boolean',
        ]);

        /** @var Courierapi|null $pathao */
        $pathao = Courierapi::query()
            ->where('type', 'pathao')
            ->first();

        if (!$pathao) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Pathao configuration not found. Please configure Pathao first.',
            ], 422);
        }

        try {
            $token = $this->resolvePathaoAccessToken($pathao, $request->boolean('refresh_token'));
            $areas = $this->fetchPathaoAreaOptionsFromApi($pathao, $token, (int) $validated['zone_id']);

            return response()->json([
                'success' => true,
                'status' => 'success',
                'data' => [
                    'zone_id' => (int) $validated['zone_id'],
                    'areas' => $areas,
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to load Pathao areas.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Unable to load Pathao areas right now.',
            ], 422);
        }
    }

    /**
     * Print orders (styled HTML response for bulk invoice printing)
     */
    public function printOrders(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'min:1'],
        ]);

        $orderIds = collect($validated['order_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No valid order ids were provided.',
            ], 422);
        }

        $orders = Order::query()
            ->with([
                'orderdetails:id,order_id,product_name,product_size,product_color,sale_price,qty,image',
                'shipping:id,order_id,name,phone,address,area',
                'payment:id,order_id,payment_method',
            ])
            ->whereIn('id', $orderIds->all())
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No orders were found for printing.',
            ], 404);
        }

        $orderPriority = array_flip($orderIds->all());
        $orders = $orders
            ->sortBy(fn ($order) => $orderPriority[(int) $order->id] ?? PHP_INT_MAX)
            ->values();

        $generalSetting = GeneralSetting::query()
            ->where('status', 1)
            ->orderByDesc('id')
            ->first();

        if (!$generalSetting) {
            $generalSetting = GeneralSetting::query()->orderByDesc('id')->first();
        }

        $contact = Contact::query()
            ->where('status', 1)
            ->orderByDesc('id')
            ->first();

        if (!$contact) {
            $contact = Contact::query()->orderByDesc('id')->first();
        }

        $companyName = $this->escapePrintValue($generalSetting?->name ?? config('app.name', 'Company'));
        $companyAddress = $this->escapePrintValue($contact?->address ?? '');
        $companyPhone = $this->escapePrintValue($contact?->hotline ?: ($contact?->phone ?: ($generalSetting?->hotline ?? '')));
        $logoPath = $generalSetting?->logo ?? $generalSetting?->white_logo ?? $generalSetting?->dark_logo ?? null;
        $companyLogo = $this->resolveImageUrl(is_string($logoPath) ? $logoPath : null);
        $companyLogoHtml = $companyLogo
            ? '<img src="' . $this->escapePrintValue($companyLogo) . '" alt="' . $companyName . '" class="brand-logo" />'
            : '';

        $cardsHtml = '';

        foreach ($orders as $order) {
            $customerName = $this->escapePrintValue($order->shipping?->name ?? '');
            $customerPhone = $this->escapePrintValue($order->shipping?->phone ?? '');
            $customerAddress = $this->escapePrintValue($order->shipping?->address ?? '');
            $customerArea = trim((string) ($order->shipping?->area ?? ''));
            $invoiceId = $this->escapePrintValue($order->invoice_id ?? '');
            $createdAt = $this->escapePrintValue($this->formatPrintDate($order->created_at));
            $paymentMethod = $this->escapePrintValue($this->resolvePaymentMethodLabel($order->payment?->payment_method));

            $shippingCharge = (float) ($order->shipping_charge ?? 0);
            $discount = (float) ($order->discount ?? 0);
            $subTotal = 0.0;
            $itemRowsHtml = '';

            foreach ($order->orderdetails as $detail) {
                $qty = (float) ($detail->qty ?? 0);
                $salePrice = (float) ($detail->sale_price ?? 0);
                $lineTotal = $qty * $salePrice;
                $subTotal += $lineTotal;

                $productName = $this->escapePrintValue($detail->product_name ?? '');
                $variantMeta = [];
                if (!empty($detail->product_size)) {
                    $variantMeta[] = 'Size: ' . $this->escapePrintValue($detail->product_size);
                }
                if (!empty($detail->product_color)) {
                    $variantMeta[] = 'Color: ' . $this->escapePrintValue($detail->product_color);
                }
                $variantMetaHtml = !empty($variantMeta)
                    ? '<div class="product-meta">' . implode(' | ', $variantMeta) . '</div>'
                    : '';

                $thumb = $this->resolveImageUrl($detail->image);
                $thumbHtml = $thumb
                    ? '<img src="' . $this->escapePrintValue($thumb) . '" alt="Product" class="product-thumb" />'
                    : '<span class="product-thumb placeholder"></span>';

                $itemRowsHtml .= '<tr>'
                    . '<td>'
                    . '<div class="product-cell">'
                    . $thumbHtml
                    . '<div><div class="product-name">' . $productName . '</div>' . $variantMetaHtml . '</div>'
                    . '</div>'
                    . '</td>'
                    . '<td class="center-cell">' . $this->escapePrintValue($this->formatPrintQuantity($qty)) . '</td>'
                    . '<td class="right-cell">' . $this->escapePrintValue($this->formatPrintMoney($lineTotal)) . '</td>'
                    . '</tr>';
            }

            if ($itemRowsHtml === '') {
                $itemRowsHtml = '<tr><td>-</td><td class="center-cell">0</td><td class="right-cell">0 Tk</td></tr>';
            }

            $deliveryText = $this->formatPrintMoney($shippingCharge);
            $discountText = $this->formatPrintMoney($discount);
            $totalText = $this->formatPrintMoney($subTotal + $shippingCharge - $discount);

            $areaWithCharge = trim($customerArea);
            if ($areaWithCharge !== '') {
                $areaWithCharge .= ' ' . $deliveryText;
            } else {
                $areaWithCharge = $deliveryText;
            }

            $cardsHtml .= '<section class="print-card">'
                . '<div class="card-head">'
                . '<div class="head-col customer-col">'
                . '<p class="customer-line customer-name">' . $customerName . '</p>'
                . '<p class="customer-line">' . $customerPhone . '</p>'
                . '<p class="customer-line">' . $customerAddress . '</p>'
                . '<p class="customer-line">' . $this->escapePrintValue($areaWithCharge) . '</p>'
                . '</div>'
                . '<div class="head-col brand-col">'
                . $companyLogoHtml
                . '<p class="brand-line">' . $companyAddress . '</p>'
                . '<p class="brand-line">' . $companyPhone . '</p>'
                . '</div>'
                . '<div class="head-col invoice-col">'
                . '<p class="invoice-title">Invoice #' . $invoiceId . '</p>'
                . '<p class="invoice-meta">Order Date: ' . $createdAt . '</p>'
                . '<p class="invoice-meta">Payment: ' . $paymentMethod . '</p>'
                . '</div>'
                . '</div>'
                . '<div class="card-divider"></div>'
                . '<table class="invoice-table">'
                . '<thead><tr><th>Product</th><th>Quantity</th><th>Price</th></tr></thead>'
                . '<tbody>'
                . $itemRowsHtml
                . '<tr class="summary-row"><td></td><td class="summary-label">Delivery:</td><td class="right-cell">' . $this->escapePrintValue($deliveryText) . '</td></tr>'
                . '<tr class="summary-row"><td></td><td class="summary-label">Discount(-):</td><td class="right-cell">' . $this->escapePrintValue($discountText) . '</td></tr>'
                . '<tr class="summary-row total-row"><td></td><td class="summary-label"><strong>Total:</strong></td><td class="right-cell"><strong>' . $this->escapePrintValue($totalText) . '</strong></td></tr>'
                . '</tbody>'
                . '</table>'
                . '</section>';
        }

        $html = '<!doctype html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="utf-8" />'
            . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<title>Bulk Invoice Print</title>'
            . '<style>'
            . 'body{margin:0;background:#e4e6ea;font-family:Arial,sans-serif;color:#222;}'
            . '.print-wrapper{max-width:1080px;margin:0 auto;padding:16px 12px 24px;}'
            . '.toolbar{text-align:center;margin-bottom:12px;}'
            . '.print-btn{border:0;background:#1f9d55;color:#fff;padding:6px 14px;border-radius:4px;font-size:14px;font-weight:600;cursor:pointer;}'
            . '.print-card{background:#fff;border:1px solid #d7d9dd;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,.05);margin-bottom:8px;overflow:hidden;page-break-inside:avoid;}'
            . '.card-head{display:grid;grid-template-columns:1.2fr 1.6fr 1fr;gap:12px;padding:10px 10px 8px;}'
            . '.head-col{min-height:72px;}'
            . '.customer-line{margin:0 0 3px;font-size:13px;line-height:1.3;}'
            . '.customer-name{font-weight:700;}'
            . '.brand-col{text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;}'
            . '.brand-logo{max-height:22px;width:auto;display:block;margin-bottom:4px;}'
            . '.brand-line{margin:0;font-size:11px;line-height:1.25;}'
            . '.invoice-col{text-align:right;}'
            . '.invoice-title{margin:0 0 3px;font-size:22px;font-weight:700;}'
            . '.invoice-meta{margin:0 0 2px;font-size:13px;line-height:1.3;}'
            . '.card-divider{border-top:1px solid #e9b7b7;}'
            . '.invoice-table{width:100%;border-collapse:collapse;}'
            . '.invoice-table th,.invoice-table td{border:1px solid #d9dbe0;padding:6px 8px;font-size:12px;}'
            . '.invoice-table thead th{background:#efefef;font-weight:700;text-align:center;}'
            . '.product-cell{display:flex;align-items:center;gap:8px;}'
            . '.product-thumb{width:16px;height:16px;object-fit:cover;border:1px solid #d3d3d3;flex-shrink:0;}'
            . '.product-thumb.placeholder{display:inline-block;background:#f2f2f2;}'
            . '.product-name{font-size:11px;line-height:1.35;}'
            . '.product-meta{font-size:10px;color:#666;line-height:1.35;}'
            . '.center-cell{text-align:center;white-space:nowrap;}'
            . '.right-cell{text-align:right;white-space:nowrap;}'
            . '.summary-row td:first-child{background:#fff;}'
            . '.summary-label{font-size:12px;}'
            . '.total-row td{font-weight:700;}'
            . '@media (max-width:860px){.card-head{grid-template-columns:1fr;}.invoice-col{text-align:left;}.brand-col{align-items:flex-start;text-align:left;}}'
            . '@media print{body{background:#fff;}.print-wrapper{max-width:100%;padding:0;}.no-print{display:none !important;}.print-card{box-shadow:none;border-color:#d1d5db;margin-bottom:8px;}}'
            . '@page{margin:8mm;}'
            . '</style>'
            . '</head>'
            . '<body>'
            . '<main class="print-wrapper">'
            . '<div class="toolbar no-print"><button type="button" class="print-btn" onclick="window.print()">Print</button></div>'
            . $cardsHtml
            . '</main>'
            . '</body>'
            . '</html>';

        return response()->json([
            'success' => true,
            'view' => $html,
        ]);
    }

    /**
     * Get invoice data
     */
    public function invoice($invoiceId)
    {
        $this->orderStatusService->ensureDefaultStatuses();

        $order = Order::where('invoice_id', $invoiceId)
            ->with(['orderdetails', 'payment', 'shipping', 'customer', 'status:id,name,slug'])
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $payload = $order->toArray();
        $payload['status_name'] = $this->getStatusName($order->order_status);

        if (empty($payload['status'])) {
            $payload['status'] = [
                'id' => null,
                'name' => $payload['status_name'],
                'slug' => $this->orderStatusService->normalizeSlug((string) $payload['status_name']),
            ];
        }

        return response()->json(['success' => true, 'data' => $payload]);
    }

    /**
     * Format order for response
     */
    private function formatOrder($order, $detailed = false)
    {
        $imagePath = null;
        if ($order->relationLoaded('orderdetails') && $order->orderdetails && $order->orderdetails->count() > 0) {
            $imagePath = $order->orderdetails->first()->image ?? null;
        }

        $formatted = [
            'id' => $order->id,
            'invoice_id' => $order->invoice_id,
            'amount' => $order->amount,
            'shipping_charge' => $order->shipping_charge ?? 0,
            'discount' => $order->discount ?? 0,
            'discount_type' => $order->discount_type ?? 'fixed',
            'discount_value' => $order->discount_value ?? ($order->discount ?? 0),
            'ip_address' => $order->ip_address,
            'order_status' => $order->order_status,
            'status_name' => $this->getStatusName($order->order_status),
            'courier_name' => $order->courier_name ?? null,
            'courier_status' => $order->courier_status ?? null,
            'courier_order_id' => $order->courier_order_id ?? null,
            'courier_synced_at' => $order->courier_synced_at ?? null,
            'courier_sync_error' => $order->courier_sync_error ?? null,
            'status' => $order->status ? [
                'id' => $order->status->id,
                'name' => $order->status->name,
                'slug' => $order->status->slug ?? null,
            ] : [
                'id' => null,
                'name' => $this->getStatusName($order->order_status),
                'slug' => $this->orderStatusService->normalizeSlug($this->getStatusName($order->order_status)),
            ],
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'address' => $order->customer->address ?? '',
            ] : null,
            'shipping' => $order->shipping ? [
                'name' => $order->shipping->name,
                'phone' => $order->shipping->phone,
                'address' => $order->shipping->address,
                'area' => $order->shipping->area ?? null,
                'ip_address' => $order->shipping->ip_address ?? null,
            ] : null,
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
            ] : null,
            'image' => $this->resolveImageUrl($imagePath),
            'updated_at' => $order->updated_at,
            'created_at' => $order->created_at,
            'is_complete_order' => $order->is_complete_order ?? null,
            'success_ratio' => $order->success_ratio ?? null,
            'total_parcel' => $order->total_parcel ?? null,
            'delivered' => $order->delivered ?? null,
            'cancelled' => $order->cancelled ?? null,
            'pending' => $order->pending ?? null,
            'return_ratio' => $order->return_ratio ?? null,
        ];

        // Add detailed information if requested
        if ($detailed && $order->orderdetails) {
            $formatted['details'] = $order->orderdetails->map(function($detail) {
                return [
                    'product_name' => $detail->product_name,
                    'quantity' => $detail->qty,
                    'price' => $detail->sale_price,
                    'total' => $detail->qty * $detail->sale_price,
                ];
            });
        }

        return $formatted;
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

    private function escapePrintValue(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private function formatPrintDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            $date = $value instanceof Carbon ? $value : Carbon::parse((string) $value);
            return $date->format('d-m-y');
        } catch (Throwable $exception) {
            return '-';
        }
    }

    private function formatPrintMoney(mixed $value): string
    {
        $amount = (float) ($value ?? 0);
        $rounded = round($amount, 2);
        $fraction = abs($rounded - round($rounded));

        $formatted = $fraction < 0.00001
            ? number_format($rounded, 0, '.', '')
            : rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');

        return $formatted . ' Tk';
    }

    private function formatPrintQuantity(float $value): string
    {
        return abs($value - round($value)) < 0.00001
            ? (string) (int) round($value)
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function resolvePaymentMethodLabel(?string $paymentMethod): string
    {
        $normalized = strtolower(trim((string) $paymentMethod));
        if ($normalized === '' || $normalized === 'null') {
            return '-';
        }

        return match ($normalized) {
            'cod', 'cash on delivery', 'cash_on_delivery' => 'Cash On Delivery',
            'bkash' => 'bKash',
            default => Str::title(str_replace(['_', '-'], ' ', $normalized)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDropshippingPayload(Order $order, string $sellerCode): array
    {
        $shipping = $order->shipping;
        $customer = $order->customer;
        $districtName = $this->resolveDistrictName($order->district);
        if ($districtName === '') {
            $districtName = trim((string) ($shipping?->area ?? $customer?->district ?? ''));
        }
        if ($districtName === '') {
            $districtName = 'Unknown';
        }

        $itemErrors = [];

        $items = $order->orderdetails
            ->map(function ($detail) use (&$itemErrors) {
                $mappedItem = $this->mapDropshippingOrderItem($detail);
                if ($mappedItem === null) {
                    $detailId = isset($detail->id) && is_numeric($detail->id)
                        ? (int) $detail->id
                        : null;

                    $itemErrors[] = $detailId
                        ? "Missing product_sku for order item #{$detailId}."
                        : 'Missing product_sku for one or more order items.';
                }

                return $mappedItem;
            })
            ->filter()
            ->values()
            ->all();

        if (!empty($items) && (int) ($order->discount ?? 0) > 0) {
            $items = $this->applyDiscountToDropshippingItems(
                $items,
                (int) ($order->discount ?? 0)
            );
        }

        $payload = [
            'seller_code' => $sellerCode,
            'status' => 'received',
            'courier_status' => 'pending',
            'deliver_charge' => max(0, (float) ($order->shipping_charge ?? 0)),
            'notes' => $order->note ?? '',
            'customer_name' => $shipping?->name ?? $customer?->name ?? '',
            'customer_phone' => $shipping?->phone ?? $customer?->phone ?? '',
            'district' => $districtName,
            'location_type' => $shipping?->area ?? '',
            'area' => $shipping?->area ?? '',
            'address' => $shipping?->address ?? '',
            'items' => $items,
        ];

        $customerEmail = $shipping?->email ?? $customer?->email ?? null;
        if (is_string($customerEmail) && trim($customerEmail) !== '') {
            $payload['customer_email'] = trim($customerEmail);
        }

        if (!empty($itemErrors)) {
            $payload['item_errors'] = array_values(array_unique($itemErrors));
        }

        return $payload;
    }

    private function getSellerCode(Request $request): ?string
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'seller_code')) {
            $query = DB::table('users')->select('seller_code');

            if (Schema::hasColumn('users', 'id') && $request->user()) {
                $query->where('id', $request->user()->id);
            }

            $sellerCode = trim((string) $query->value('seller_code'));
            if ($sellerCode !== '') {
                return $sellerCode;
            }
        }

        $fallbackSellerCode = trim((string) config('services.dropshipping.seller_code', ''));
        if ($fallbackSellerCode !== '') {
            return $fallbackSellerCode;
        }

        return null;
    }

    private function resolveDistrictName(mixed $district): string
    {
        if ($district === null || $district === '') {
            return '';
        }

        if (is_numeric($district) && Schema::hasTable('districts')) {
            $districtRecord = District::query()
                ->select('name')
                ->find((int) $district);

            if ($districtRecord && is_string($districtRecord->name) && trim($districtRecord->name) !== '') {
                return trim($districtRecord->name);
            }
        }

        return trim((string) $district);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapDropshippingOrderItem(mixed $detail): ?array
    {
        if (!$detail) {
            return null;
        }

        $productSku = $this->resolveDropshippingProductSku($detail);
        if ($productSku === '') {
            return null;
        }

        $productSizeId = $this->normalizeDropshippingSizeId(
            $detail->product_size_id ?? $detail->product_size ?? null
        );
        $productColorIds = $this->normalizeDropshippingColorIds(
            $detail->product_color_id ?? $detail->product_color ?? null
        );
        $productColorId = !empty($productColorIds) ? $productColorIds[0] : null;

        return [
            'product_sku' => $productSku,
            'quantity' => (int) ($detail->qty ?? 0),
            'price' => (float) ($detail->sale_price ?? 0),
            'product_size_id' => $productSizeId,
            'product_color_id' => $productColorId,
        ];
    }

    private function resolveDropshippingProductSku(mixed $detail): string
    {
        $directSku = trim((string) ($detail->product_sku ?? ''));
        if ($directSku !== '') {
            return $directSku;
        }

        $relatedProductSku = trim((string) (
            data_get($detail, 'product.sku')
            ?? data_get($detail, 'product.product_code')
            ?? ''
        ));

        if ($relatedProductSku !== '') {
            return $relatedProductSku;
        }

        $productId = isset($detail->product_id) && is_numeric($detail->product_id)
            ? (int) $detail->product_id
            : 0;

        if ($productId <= 0) {
            return '';
        }

        if (array_key_exists($productId, $this->dropshippingProductSkuCache)) {
            return $this->dropshippingProductSkuCache[$productId];
        }

        $productSku = trim((string) (
            Product::query()
                ->where('id', $productId)
                ->value('product_code')
            ?? ''
        ));

        $this->dropshippingProductSkuCache[$productId] = $productSku;

        return $productSku;
    }

    private function normalizeDropshippingSizeId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'N/A') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeDropshippingColorIds(mixed $value): array
    {
        if ($value === null || $value === '' || $value === 'N/A') {
            return [];
        }

        if (is_array($value)) {
            return $this->castDropshippingColorIds($value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->castDropshippingColorIds($decoded);
            }

            $parts = array_filter(
                array_map('trim', explode(',', $value)),
                fn ($item) => $item !== ''
            );

            return $this->castDropshippingColorIds($parts);
        }

        return [];
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private function castDropshippingColorIds(array $values): array
    {
        return array_values(array_map(
            fn ($item) => is_numeric($item) ? (int) $item : $item,
            array_filter($values, fn ($item) => $item !== null && $item !== '')
        ));
    }

    private function normalizeDropshippingExceptionMessage(string $rawMessage): string
    {
        $message = trim($rawMessage);
        if ($message === '') {
            return 'Dropshipping request failed. Please try again.';
        }

        $jsonPosition = strpos($message, '{');
        if ($jsonPosition !== false) {
            $jsonPayload = substr($message, $jsonPosition);
            $decoded = json_decode($jsonPayload, true);
            if (is_array($decoded) && array_key_exists('message', $decoded)) {
                $apiMessage = $decoded['message'];
                if (is_string($apiMessage) && trim($apiMessage) !== '') {
                    return trim($apiMessage);
                }

                if (is_array($apiMessage)) {
                    $flattened = collect($apiMessage)
                        ->flatMap(function ($value) {
                            if (is_array($value)) {
                                return array_map(fn ($item) => (string) $item, $value);
                            }

                            return [(string) $value];
                        })
                        ->map(fn ($value) => trim((string) $value))
                        ->filter()
                        ->values()
                        ->all();

                    if (!empty($flattened)) {
                        return implode(' ', $flattened);
                    }
                }
            }
        }

        return mb_substr($message, 0, 350);
    }

    private function applyOrderStatusUpdate(array $orderIds, int $status): void
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (empty($orderIds)) {
            return;
        }

        $statusValue = (string) $status;

        DB::transaction(function () use ($orderIds, $statusValue) {
            $orders = Order::query()
                ->with(['orderdetails:id,order_id,product_id,qty'])
                ->whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                $previousStatusValue = (string) $order->order_status;
                $productQtyMap = $this->buildProductQtyMapFromDetails($order->orderdetails);

                $this->reconcileOrderStockByDifference(
                    $previousStatusValue,
                    $statusValue,
                    $productQtyMap,
                    $productQtyMap
                );

                $order->order_status = $statusValue;
                $order->save();
            }
        });
    }

    /**
     * @return array<int, int>
     */
    private function buildProductQtyMapFromDetails(Collection $details): array
    {
        $qtyMap = [];

        foreach ($details as $detail) {
            $productId = isset($detail->product_id) ? (int) $detail->product_id : 0;
            if ($productId <= 0) {
                continue;
            }

            $qtyMap[$productId] = ($qtyMap[$productId] ?? 0) + max(0, (int) ($detail->qty ?? 0));
        }

        return $qtyMap;
    }

    /**
     * @param array<int, int> $previousProductQtyMap
     * @param array<int, int> $nextProductQtyMap
     */
    private function reconcileOrderStockByDifference(
        string $previousStatusValue,
        string $nextStatusValue,
        array $previousProductQtyMap,
        array $nextProductQtyMap
    ): void
    {
        $previousReserved = $this->orderStatusService->isCancelledValue($previousStatusValue)
            ? []
            : $previousProductQtyMap;
        $nextReserved = $this->orderStatusService->isCancelledValue($nextStatusValue)
            ? []
            : $nextProductQtyMap;

        $productIds = array_values(array_unique(array_merge(
            array_keys($previousReserved),
            array_keys($nextReserved)
        )));

        if (empty($productIds)) {
            return;
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $decrementMap = [];
        $incrementMap = [];

        foreach ($productIds as $productId) {
            $beforeQty = (int) ($previousReserved[$productId] ?? 0);
            $afterQty = (int) ($nextReserved[$productId] ?? 0);
            $delta = $afterQty - $beforeQty;

            if ($delta === 0) {
                continue;
            }

            /** @var Product|null $product */
            $product = $products->get($productId);
            if (!$product) {
                continue;
            }

            if ($delta > 0) {
                if ((int) $product->stock < $delta) {
                    throw ValidationException::withMessages([
                        'items' => [
                            sprintf(
                                'Insufficient stock for product "%s". Required additional quantity: %d, available: %d.',
                                $product->name ?? ('#' . $productId),
                                $delta,
                                (int) $product->stock
                            ),
                        ],
                    ]);
                }

                $decrementMap[$productId] = $delta;
                continue;
            }

            $incrementMap[$productId] = abs($delta);
        }

        foreach ($decrementMap as $productId => $qty) {
            Product::query()->where('id', $productId)->decrement('stock', $qty);
        }

        foreach ($incrementMap as $productId => $qty) {
            Product::query()->where('id', $productId)->increment('stock', $qty);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return \Illuminate\Support\Collection<int, OrderDetails>
     */
    private function syncOrderItems(Order $order, array $items): Collection
    {
        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => ['At least one order item is required.'],
            ]);
        }

        $existingDetails = OrderDetails::query()
            ->where('order_id', $order->id)
            ->get()
            ->keyBy('id');

        $incomingIds = collect($items)
            ->pluck('id')
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($incomingIds->isNotEmpty()) {
            $invalidId = $incomingIds->first(fn ($id) => !$existingDetails->has($id));
            if ($invalidId) {
                throw ValidationException::withMessages([
                    'items' => ["Order item #{$invalidId} does not belong to this order."],
                ]);
            }
        }

        $productIds = collect($items)
            ->pluck('product_id')
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $products = Product::query()
            ->with('image:id,product_id,image')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $keptIds = [];

        foreach ($items as $index => $item) {
            $itemId = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;
            /** @var OrderDetails $detail */
            $detail = $itemId ? $existingDetails->get($itemId) : new OrderDetails();
            if (!$detail) {
                throw ValidationException::withMessages([
                    "items.{$index}.id" => ['Invalid order item reference.'],
                ]);
            }

            $productId = isset($item['product_id']) && is_numeric($item['product_id'])
                ? (int) $item['product_id']
                : 0;
            /** @var Product|null $product */
            $product = $productId > 0 ? $products->get($productId) : null;

            if ($productId > 0 && !$product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ['Selected product not found.'],
                ]);
            }

            $qty = max(1, (int) ($item['qty'] ?? 1));
            $salePrice = (int) round((float) ($item['sale_price'] ?? 0));
            $purchasePrice = (int) round((float) (
                $item['purchase_price']
                ?? $product?->purchase_price
                ?? $detail->purchase_price
                ?? 0
            ));

            $detail->order_id = (int) $order->id;
            $detail->product_id = $productId > 0 ? $productId : 0;
            $detail->product_name = trim((string) (
                $item['product_name']
                ?? $product?->name
                ?? $detail->product_name
                ?? 'Custom Item'
            ));
            $detail->qty = $qty;
            $detail->sale_price = $salePrice;
            $detail->purchase_price = $purchasePrice;
            $detail->image = trim((string) (
                $item['image']
                ?? $product?->image?->image
                ?? $detail->image
                ?? 'default.png'
            ));
            $detailSku = trim((string) (
                $item['product_sku']
                ?? $product?->sku
                ?? $product?->product_code
                ?? $detail->product_sku
                ?? ''
            ));
            $detail->product_sku = $detailSku !== '' ? $detailSku : null;
            $detail->product_size = array_key_exists('product_size', $item)
                ? (string) ($item['product_size'] ?? '')
                : ($detail->product_size ?? null);
            $detail->product_color = array_key_exists('product_color', $item)
                ? (string) ($item['product_color'] ?? '')
                : ($detail->product_color ?? null);
            $detail->save();

            $keptIds[] = (int) $detail->id;
        }

        if (!empty($keptIds)) {
            OrderDetails::query()
                ->where('order_id', $order->id)
                ->whereNotIn('id', $keptIds)
                ->delete();
        }

        return OrderDetails::query()
            ->where('order_id', $order->id)
            ->get();
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{0:string,1:float}
     */
    private function resolveDiscountInput(
        array $validated,
        string $defaultType = 'fixed',
        float $defaultValue = 0.0
    ): array {
        $discountType = strtolower(trim((string) ($validated['discount_type'] ?? $defaultType)));
        if (!in_array($discountType, ['fixed', 'percentage'], true)) {
            $discountType = 'fixed';
        }

        $rawValue = $defaultValue;
        if (array_key_exists('discount_value', $validated)) {
            $rawValue = (float) ($validated['discount_value'] ?? 0);
        } elseif (array_key_exists('discount', $validated)) {
            $rawValue = (float) ($validated['discount'] ?? 0);
        }

        $discountValue = max(0, $rawValue);
        if ($discountType === 'percentage' && $discountValue > 100) {
            throw ValidationException::withMessages([
                'discount_value' => ['Percentage discount can not be greater than 100.'],
            ]);
        }

        return [$discountType, round($discountValue, 2)];
    }

    private function calculateDiscountAmount(int $subTotal, string $discountType, float $discountValue): int
    {
        $normalizedType = strtolower(trim($discountType));
        $safeSubTotal = max(0, $subTotal);
        $safeDiscountValue = max(0, $discountValue);

        if ($normalizedType === 'percentage') {
            $discountPercentage = min(100, $safeDiscountValue);
            return (int) round(($safeSubTotal * $discountPercentage) / 100);
        }

        return (int) round(min($safeSubTotal, $safeDiscountValue));
    }

    /**
     * Recalculate persisted financial fields from current order line-items.
     *
     * @return array{sub_total:int,shipping_charge:int,discount:int,discount_type:string,discount_value:float,amount:int}
     */
    private function recalculateOrderFinancials(Order $order): array
    {
        $subTotal = $this->calculateOrderSubTotal((int) $order->id);
        $shippingCharge = max(0, (int) ($order->shipping_charge ?? 0));

        $discountType = strtolower(trim((string) ($order->discount_type ?? 'fixed')));
        if (!in_array($discountType, ['fixed', 'percentage'], true)) {
            $discountType = 'fixed';
        }

        $discountValue = $this->resolveDiscountValueFromOrder($order, $discountType, $subTotal);
        $discountAmount = $this->calculateDiscountAmount($subTotal, $discountType, $discountValue);
        $amount = max(0, $subTotal + $shippingCharge - $discountAmount);

        $existingDiscountValue = (float) ($order->discount_value ?? 0);
        $discountValueChanged = abs($existingDiscountValue - $discountValue) > 0.00001;

        if (
            (int) ($order->shipping_charge ?? 0) !== $shippingCharge
            || (string) ($order->discount_type ?? '') !== $discountType
            || $discountValueChanged
            || (int) ($order->discount ?? 0) !== $discountAmount
            || (int) ($order->amount ?? 0) !== $amount
        ) {
            $order->shipping_charge = $shippingCharge;
            $order->discount_type = $discountType;
            $order->discount_value = $discountValue;
            $order->discount = $discountAmount;
            $order->amount = $amount;
            $order->save();
        }

        return [
            'sub_total' => $subTotal,
            'shipping_charge' => $shippingCharge,
            'discount' => $discountAmount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'amount' => $amount,
        ];
    }

    private function resolveDiscountValueFromOrder(Order $order, string $discountType, int $subTotal): float
    {
        $storedDiscountAmount = max(0, (float) ($order->discount ?? 0));
        $rawDiscountValue = $order->discount_value;
        $hasDiscountValue = is_numeric($rawDiscountValue);

        if ($discountType === 'percentage') {
            if ($hasDiscountValue && (float) $rawDiscountValue > 0) {
                return round(min(100, max(0, (float) $rawDiscountValue)), 2);
            }

            if ($subTotal > 0 && $storedDiscountAmount > 0) {
                return round(min(100, ($storedDiscountAmount * 100) / $subTotal), 2);
            }

            return 0.0;
        }

        if ($hasDiscountValue && (float) $rawDiscountValue > 0) {
            return round(max(0, (float) $rawDiscountValue), 2);
        }

        return round($storedDiscountAmount, 2);
    }

    /**
     * Apply order-level discount to dropshipping item prices so item subtotal matches discounted order value.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applyDiscountToDropshippingItems(array $items, int $discountAmount): array
    {
        if (empty($items) || $discountAmount <= 0) {
            return $items;
        }

        $normalizedItems = [];
        $subTotalCents = 0;

        foreach ($items as $index => $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = max(0, (float) ($item['price'] ?? 0));
            $lineTotalCents = max(0, (int) round($unitPrice * $quantity * 100));

            $normalizedItems[$index] = [
                'item' => $item,
                'quantity' => $quantity,
                'line_total_cents' => $lineTotalCents,
            ];

            $subTotalCents += $lineTotalCents;
        }

        if ($subTotalCents <= 0) {
            return $items;
        }

        $discountCents = min($subTotalCents, max(0, $discountAmount * 100));
        if ($discountCents <= 0) {
            return $items;
        }

        $lineDiscounts = [];
        $fractionalRemainders = [];
        $allocatedDiscount = 0;

        foreach ($normalizedItems as $index => $normalizedItem) {
            $lineTotalCents = $normalizedItem['line_total_cents'];
            if ($lineTotalCents <= 0) {
                $lineDiscounts[$index] = 0;
                $fractionalRemainders[$index] = -1;
                continue;
            }

            $exactDiscount = ($discountCents * $lineTotalCents) / $subTotalCents;
            $baseDiscount = min($lineTotalCents, (int) floor($exactDiscount));

            $lineDiscounts[$index] = $baseDiscount;
            $fractionalRemainders[$index] = $exactDiscount - $baseDiscount;
            $allocatedDiscount += $baseDiscount;
        }

        $remainingDiscount = max(0, $discountCents - $allocatedDiscount);
        if ($remainingDiscount > 0) {
            $indexes = array_keys($normalizedItems);
            usort($indexes, function ($left, $right) use ($fractionalRemainders, $normalizedItems, $lineDiscounts) {
                $leftCapacity = $normalizedItems[$left]['line_total_cents'] - ($lineDiscounts[$left] ?? 0);
                $rightCapacity = $normalizedItems[$right]['line_total_cents'] - ($lineDiscounts[$right] ?? 0);

                if ($leftCapacity <= 0 && $rightCapacity <= 0) {
                    return 0;
                }
                if ($leftCapacity <= 0) {
                    return 1;
                }
                if ($rightCapacity <= 0) {
                    return -1;
                }

                return $fractionalRemainders[$right] <=> $fractionalRemainders[$left];
            });

            foreach ($indexes as $index) {
                if ($remainingDiscount <= 0) {
                    break;
                }

                $capacity = $normalizedItems[$index]['line_total_cents'] - ($lineDiscounts[$index] ?? 0);
                if ($capacity <= 0) {
                    continue;
                }

                $lineDiscounts[$index] = ($lineDiscounts[$index] ?? 0) + 1;
                $remainingDiscount--;
            }
        }

        $discountedItems = [];
        foreach ($normalizedItems as $index => $normalizedItem) {
            $lineDiscountCents = max(0, (int) ($lineDiscounts[$index] ?? 0));
            $discountedLineTotalCents = max(
                0,
                $normalizedItem['line_total_cents'] - $lineDiscountCents
            );
            $quantity = $normalizedItem['quantity'];

            $item = $normalizedItem['item'];
            $item['price'] = $quantity > 0
                ? round(($discountedLineTotalCents / $quantity) / 100, 4)
                : 0;

            $discountedItems[] = $item;
        }

        return $discountedItems;
    }

    private function recordCourierDispatch(
        Order $order,
        string $courierName,
        string $courierStatus,
        ?string $courierOrderId = null,
        ?string $error = null,
        mixed $responsePayload = null
    ): void {
        $updates = [];

        if (Schema::hasColumn('orders', 'courier_name')) {
            $updates['courier_name'] = trim(strtolower($courierName));
        }
        if (Schema::hasColumn('orders', 'courier_status')) {
            $updates['courier_status'] = trim(strtolower($courierStatus));
        }
        if (Schema::hasColumn('orders', 'courier_order_id')) {
            $updates['courier_order_id'] = $courierOrderId !== null && trim($courierOrderId) !== ''
                ? trim($courierOrderId)
                : null;
        }
        if (Schema::hasColumn('orders', 'courier_synced_at')) {
            $updates['courier_synced_at'] = now();
        }
        if (Schema::hasColumn('orders', 'courier_sync_error')) {
            $updates['courier_sync_error'] = $error !== null && trim($error) !== ''
                ? trim($error)
                : null;
        }
        if (Schema::hasColumn('orders', 'courier_response_payload')) {
            $updates['courier_response_payload'] = $this->normalizeCourierResponsePayload($responsePayload);
        }

        if (empty($updates)) {
            return;
        }

        Order::query()->whereKey($order->id)->update($updates);
        foreach ($updates as $key => $value) {
            $order->{$key} = $value;
        }
    }

    private function dispatchToSteadfastCourier(Request $request, SteadfastCourierService $steadfastCourierService)
    {
        $validated = $request->validate([
            'order_id' => 'nullable|integer|exists:orders,id',
            'order_ids' => 'nullable|array|min:1',
            'order_ids.*' => 'required|integer|exists:orders,id',
        ]);

        $orderIds = collect($validated['order_ids'] ?? [])
            ->when(isset($validated['order_id']), fn ($collection) => $collection->push($validated['order_id']))
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($orderIds)) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'No valid order selected.',
            ], 422);
        }

        $sentOrders = [];
        $failedOrders = [];

        foreach ($orderIds as $orderId) {
            $order = Order::with(['shipping', 'customer', 'orderdetails'])->find($orderId);
            if (!$order) {
                $failedOrders[] = [
                    'order_id' => $orderId,
                    'message' => 'Order not found.',
                ];
                continue;
            }

            $this->recalculateOrderFinancials($order);

            if ($this->isOrderAlreadyTakenByCourier($order)) {
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $this->buildOrderAlreadyTakenMessage($order),
                ];
                continue;
            }

            if (!$this->isCompletedOrderForCourierDispatch($order)) {
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => 'Only completed orders can be sent to Steadfast.',
                ];
                continue;
            }

            try {
                $dispatchResult = $steadfastCourierService->dispatch($order);
            } catch (ValidationException $exception) {
                $failedMessage = collect($exception->errors())->flatten()->first() ?: 'Steadfast request failed.';

                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $failedMessage,
                ];
                continue;
            } catch (Throwable $exception) {
                $failedMessage = trim((string) $exception->getMessage()) ?: 'Steadfast request failed.';
                $this->recordCourierDispatch($order, 'steadfast', 'failed', null, $failedMessage, [
                    'exception_message' => $failedMessage,
                ]);

                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $failedMessage,
                ];
                continue;
            }

            if ($dispatchResult['success']) {
                $this->recordCourierDispatch(
                    $order,
                    'steadfast',
                    (string) ($dispatchResult['courier_status'] ?? 'sent'),
                    $dispatchResult['courier_order_id'] ?? null,
                    null,
                    $dispatchResult['response_payload'] ?? null
                );

                $sentOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'courier_order_id' => $dispatchResult['courier_order_id'] ?? null,
                ];
                continue;
            }

            $failedMessage = trim((string) ($dispatchResult['message'] ?? 'Steadfast request failed.'));
            $this->recordCourierDispatch(
                $order,
                'steadfast',
                'failed',
                null,
                $failedMessage,
                $dispatchResult['response_payload'] ?? null
            );

            $failedOrders[] = [
                'order_id' => (int) $order->id,
                'invoice_id' => $order->invoice_id,
                'message' => $failedMessage,
            ];
        }

        if (!empty($failedOrders)) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => $failedOrders[0]['message'] ?? 'Failed to send order(s) to Steadfast.',
                'sent_orders' => $sentOrders,
                'failed_orders' => $failedOrders,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => count($sentOrders) === 1
                ? 'Order sent to Steadfast successfully.'
                : 'Orders sent to Steadfast successfully.',
            'sent_orders' => $sentOrders,
        ]);
    }

    private function resolvePathaoCreateOrderEndpoint(string $configuredUrl = ''): string
    {
        $candidate = trim($configuredUrl);
        if ($candidate === '') {
            return $this->pathaoDefaultBaseUrl() . self::PATHAO_CREATE_ORDER_PATH;
        }

        if (preg_match('/\/aladdin\/api\/v1\/issue-token\/?$/i', $candidate)) {
            $candidate = preg_replace('/\/issue-token\/?$/i', '', $candidate) ?: $candidate;
            return rtrim($candidate, '/') . '/orders';
        }

        if (preg_match('/\/aladdin\/api\/v1\/orders\/?$/i', $candidate) || preg_match('/\/orders\/?$/i', $candidate)) {
            return rtrim($candidate, '/');
        }

        return rtrim($candidate, '/') . self::PATHAO_CREATE_ORDER_PATH;
    }

    private function resolvePathaoTokenEndpoint(string $configuredUrl = ''): string
    {
        $candidate = trim($configuredUrl);
        if ($candidate === '') {
            return $this->pathaoDefaultBaseUrl() . self::PATHAO_ISSUE_TOKEN_PATH;
        }

        if (preg_match('/\/issue-token\/?$/i', $candidate)) {
            return rtrim($candidate, '/');
        }

        if (preg_match('/\/aladdin\/api\/v1\/orders\/?$/i', $candidate)) {
            $candidate = preg_replace('/\/orders\/?$/i', '', $candidate) ?: $candidate;
        } elseif (preg_match('/\/orders\/?$/i', $candidate)) {
            $candidate = preg_replace('/\/orders\/?$/i', '', $candidate) ?: $candidate;
        }

        return rtrim($candidate, '/') . self::PATHAO_ISSUE_TOKEN_PATH;
    }

    private function resolvePathaoAccessToken(Courierapi $pathao, bool $forceRefresh = false): string
    {
        $existingToken = trim((string) ($pathao->token ?? ''));
        if (!$forceRefresh && $existingToken !== '' && !$pathao->tokenExpired()) {
            return $existingToken;
        }

        $credentials = $this->resolvePathaoCredentials($pathao);

        $tokenResponse = $this->requestPathaoToken(
            $credentials['client_id'],
            $credentials['client_secret'],
            $credentials['username'],
            $credentials['password'],
            trim((string) ($pathao->url ?? ''))
        );

        $pathao->token = $tokenResponse['token'];
        $pathao->token_expires_at = $tokenResponse['expires_at'];
        $pathao->save();

        return trim((string) ($pathao->token ?? ''));
    }

    /**
     * @return array{token:string,expires_at:\Illuminate\Support\Carbon}
     */
    private function requestPathaoToken(
        string $clientId,
        string $clientSecret,
        string $username,
        string $password,
        string $configuredUrl = ''
    ): array {
        $endpoint = $this->resolvePathaoTokenEndpoint($configuredUrl);
        $response = Http::acceptJson()
            ->asJson()
            ->post($endpoint, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => trim((string) config('services.pathao.grant_type', 'password')) ?: 'password',
                'username' => $username,
                'password' => $password,
            ]);

        if (!$response->successful()) {
            $message = $this->resolveCourierErrorMessage($response, 'Pathao token request failed.');
            throw ValidationException::withMessages([
                'pathao' => [$message],
            ]);
        }

        $token = trim((string) (
            $response->json('access_token')
            ?? $response->json('data.access_token')
            ?? $response->json('result.access_token')
            ?? ''
        ));
        if ($token === '') {
            throw ValidationException::withMessages([
                'pathao' => ['Pathao token response did not include an access token.'],
            ]);
        }

        $expiresInRaw = $response->json('expires_in')
            ?? $response->json('data.expires_in')
            ?? $response->json('result.expires_in');
        $expiresIn = is_numeric($expiresInRaw) ? (int) $expiresInRaw : 0;
        $expiresAt = $expiresIn > 0
            ? now()->addSeconds(max(60, $expiresIn))
            : now()->addHours(6);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchPathaoOrderRequest(
        string $endpoint,
        string $token,
        array $payload,
        ?string $storeId = null
    ): Response
    {
        $requestBuilder = Http::acceptJson()
            ->asJson()
            ->withToken($token);

        $normalizedStoreId = trim((string) ($storeId ?? ''));
        if ($normalizedStoreId !== '') {
            $requestBuilder = $requestBuilder->withHeaders([
                'X-Store-ID' => $normalizedStoreId,
                'store-id' => $normalizedStoreId,
            ]);
        }

        return $requestBuilder->post($endpoint, $payload);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildPathaoPayload(Order $order, array $overrides = [], ?string $storeId = null): array
    {
        $shipping = $order->shipping;
        $customer = $order->customer;

        $customerName = trim((string) ($shipping?->name ?? $customer?->name ?? ''));
        $customerPhone = trim((string) ($shipping?->phone ?? $customer?->phone ?? ''));
        $customerAddress = trim((string) ($shipping?->address ?? $customer?->address ?? ''));
        $customerArea = trim((string) ($shipping?->area ?? ''));

        $itemQuantity = max(1, (int) $order->orderdetails->sum(fn ($item) => max(0, (int) ($item->qty ?? 0))));
        $itemDescription = trim((string) $order->orderdetails
            ->pluck('product_name')
            ->filter()
            ->take(3)
            ->join(', '));

        $recipientCity = $this->normalizePathaoLocationValue($overrides['recipient_city'] ?? null)
            ?? $this->normalizePathaoLocationValue(config('services.pathao.default_city_id'))
            ?? ($customerArea !== '' ? $customerArea : 'Dhaka');

        $recipientZone = $this->normalizePathaoLocationValue($overrides['recipient_zone'] ?? null)
            ?? $this->normalizePathaoLocationValue(config('services.pathao.default_zone_id'))
            ?? ($customerArea !== '' ? $customerArea : 'Unknown');

        $recipientArea = $this->normalizePathaoLocationValue($overrides['recipient_area'] ?? null)
            ?? $this->normalizePathaoLocationValue(config('services.pathao.default_area_id'));

        $deliveryType = (int) ($overrides['delivery_type'] ?? config('services.pathao.delivery_type', 48));
        if ($deliveryType <= 0) {
            $deliveryType = 48;
        }

        $itemType = (int) ($overrides['item_type'] ?? config('services.pathao.item_type', 2));
        if ($itemType <= 0) {
            $itemType = 2;
        }

        $itemWeight = isset($overrides['item_weight']) && is_numeric($overrides['item_weight'])
            ? (float) $overrides['item_weight']
            : max(
                0.2,
                round($itemQuantity * max(0.1, (float) config('services.pathao.weight_per_item', 0.2)), 2)
            );

        $payload = [
            'merchant_order_id' => (string) $order->invoice_id,
            'recipient_name' => $customerName !== '' ? $customerName : 'Customer',
            'recipient_phone' => $customerPhone !== '' ? $customerPhone : '00000000000',
            'recipient_address' => $customerAddress !== '' ? $customerAddress : 'Address not provided',
            'recipient_city' => $recipientCity,
            'recipient_zone' => $recipientZone,
            'delivery_type' => $deliveryType,
            'item_type' => $itemType,
            'special_instruction' => trim((string) ($order->note ?? '')),
            'item_quantity' => $itemQuantity,
            'item_weight' => max(0.1, round($itemWeight, 2)),
            'item_description' => $itemDescription !== '' ? $itemDescription : 'Parcel',
            'amount_to_collect' => max(0, (float) ($order->amount ?? 0)),
        ];

        if ($recipientArea !== null) {
            $payload['recipient_area'] = $recipientArea;
        }

        $normalizedStoreId = trim((string) ($storeId ?? ''));
        if ($normalizedStoreId !== '') {
            $payload['store_id'] = is_numeric($normalizedStoreId)
                ? (int) $normalizedStoreId
                : $normalizedStoreId;
        }

        return $payload;
    }

    private function extractCourierOrderIdFromResponse(Response $response): ?string
    {
        $body = $response->json();
        $candidates = [
            data_get($body, 'data.consignment_id'),
            data_get($body, 'data.invoice_no'),
            data_get($body, 'data.invoice'),
            data_get($body, 'data.order_id'),
            data_get($body, 'data.id'),
            data_get($body, 'consignment_id'),
            data_get($body, 'invoice_no'),
            data_get($body, 'order_id'),
            data_get($body, 'id'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function resolveCourierErrorMessage(Response $response, string $fallback): string
    {
        $message = trim((string) (
            $response->json('message')
            ?? $response->json('data.message')
            ?? $response->json('result.message')
            ?? ''
        ));
        $errors = $this->extractCourierApiErrors($response);

        if (!empty($errors)) {
            $combinedErrors = implode(' ', $errors);
            if ($message === '' || $this->isGenericCourierErrorMessage($message)) {
                return mb_substr($combinedErrors, 0, 350);
            }

            return mb_substr(trim($message . ' ' . $combinedErrors), 0, 350);
        }

        if ($message !== '' && !$this->isGenericCourierErrorMessage($message)) {
            return mb_substr($message, 0, 350);
        }

        $rawBody = trim((string) $response->body());
        if ($rawBody !== '') {
            if ($message !== '' && !$this->isGenericCourierErrorMessage($message)) {
                return mb_substr($message, 0, 350);
            }
            return mb_substr($rawBody, 0, 350);
        }

        if ($message !== '') {
            return mb_substr($message, 0, 350);
        }

        return mb_substr($fallback, 0, 350);
    }

    /**
     * @return array<int, string>
     */
    private function extractCourierApiErrors(Response $response): array
    {
        $segments = [
            $response->json('errors'),
            $response->json('error'),
            $response->json('data.errors'),
            $response->json('data.error'),
            $response->json('data.validation_errors'),
            $response->json('result.error'),
            $response->json('result.errors'),
            $response->json('data.message'),
            $response->json('result.message'),
        ];

        return collect($segments)
            ->flatMap(fn ($segment) => $this->flattenCourierErrorSegment($segment))
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '' && !$this->isGenericCourierErrorMessage($item))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function flattenCourierErrorSegment(mixed $segment, ?string $field = null): array
    {
        if ($segment === null || is_bool($segment)) {
            return [];
        }

        if (is_numeric($segment)) {
            return [];
        }

        if (is_string($segment)) {
            $message = trim($segment);
            if ($message === '' || $this->shouldIgnoreCourierErrorField($field)) {
                return [];
            }

            if ($field !== null && trim($field) !== '' && !$this->isCourierErrorContainerKey($field)) {
                return [
                    sprintf('%s: %s', $this->formatCourierErrorField($field), $message),
                ];
            }

            return [$message];
        }

        if (!is_array($segment) || empty($segment)) {
            return [];
        }

        if (array_key_exists('field', $segment) && array_key_exists('message', $segment)) {
            $fieldName = trim((string) ($segment['field'] ?? ''));
            $fieldMessage = trim((string) ($segment['message'] ?? ''));

            if ($fieldMessage !== '') {
                if ($fieldName === '') {
                    return [$fieldMessage];
                }

                return [
                    sprintf('%s: %s', $this->formatCourierErrorField($fieldName), $fieldMessage),
                ];
            }
        }

        $messages = [];
        foreach ($segment as $key => $value) {
            $nextField = $field;
            if (is_string($key) && trim($key) !== '' && !$this->isCourierErrorContainerKey($key)) {
                $nextField = $key;
            }

            $messages = array_merge(
                $messages,
                $this->flattenCourierErrorSegment($value, $nextField)
            );
        }

        return $messages;
    }

    private function isCourierErrorContainerKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), [
            'errors',
            'error',
            'messages',
            'message',
            'data',
            'result',
            'details',
            'detail',
            'validation_errors',
        ], true);
    }

    private function shouldIgnoreCourierErrorField(?string $field): bool
    {
        if ($field === null) {
            return false;
        }

        return in_array(strtolower(trim($field)), [
            'success',
            'status',
            'status_code',
            'http_code',
            'code',
            'error_code',
            'type',
        ], true);
    }

    private function formatCourierErrorField(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', trim($field)));
    }

    private function isGenericCourierErrorMessage(string $message): bool
    {
        $normalized = strtolower(trim($message));
        return in_array($normalized, [
            'please fix the given errors',
            'please fix the given error',
            'validation failed',
            'unprocessable entity',
            'the given data was invalid.',
            'error',
        ], true);
    }

    private function isCompletedOrderForCourierDispatch(Order $order): bool
    {
        $statusValues = [
            $order->order_status,
            $this->getStatusName($order->order_status),
        ];

        foreach ($statusValues as $statusValue) {
            if ($this->orderStatusService->canonicalKeyFromValue($statusValue) === 'complete') {
                return true;
            }
        }

        return false;
    }

    private function isOrderAlreadyTakenByCourier(Order $order): bool
    {
        $courierOrderId = trim((string) ($order->courier_order_id ?? ''));
        if ($courierOrderId !== '') {
            return true;
        }

        $courierName = strtolower(trim((string) ($order->courier_name ?? '')));
        $courierStatus = strtolower(trim((string) ($order->courier_status ?? '')));

        if ($courierName === '') {
            return false;
        }

        return in_array($courierStatus, [
            'sent',
            'booked',
            'created',
            'processing',
            'in_transit',
            'in-transit',
            'pending_pickup',
            'picked',
        ], true);
    }

    private function buildOrderAlreadyTakenMessage(Order $order): string
    {
        $courierName = trim((string) ($order->courier_name ?? ''));
        $courierOrderId = trim((string) ($order->courier_order_id ?? ''));

        $message = 'Order already taken by courier.';
        if ($courierName !== '') {
            $message = 'Order already taken by ' . ucfirst($courierName) . '.';
        }

        if ($courierOrderId !== '') {
            $message .= ' Tracking code: ' . $courierOrderId . '.';
        }

        return $message;
    }

    private function normalizeCourierResponsePayload(mixed $responsePayload): ?string
    {
        if ($responsePayload === null) {
            return null;
        }

        if (is_string($responsePayload)) {
            $normalized = trim($responsePayload);
            if ($normalized === '') {
                return null;
            }

            $responsePayload = ['raw_body' => $normalized];
        }

        $encoded = json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : null;
    }

    /**
     * @return array{client_id:string,client_secret:string,username:string,password:string}
     */
    private function pathaoCredentialsFromEnvironment(): array
    {
        return [
            'client_id' => trim((string) config('services.pathao.client_id', '')),
            'client_secret' => trim((string) config('services.pathao.client_secret', '')),
            'username' => trim((string) config('services.pathao.username', '')),
            'password' => trim((string) config('services.pathao.password', '')),
        ];
    }

    /**
     * @return array{client_id:string,client_secret:string,username:string,password:string}
     */
    private function pathaoCredentialsFromCourier(Courierapi $pathao): array
    {
        return [
            'client_id' => trim((string) ($pathao->client_id ?? '')),
            'client_secret' => trim((string) ($pathao->client_secret ?? '')),
            'username' => trim((string) ($pathao->username ?? '')),
            'password' => trim((string) ($pathao->password ?? '')),
        ];
    }

    /**
     * @param array{client_id:string,client_secret:string,username:string,password:string} $credentials
     */
    private function pathaoCredentialsAreComplete(array $credentials): bool
    {
        return $credentials['client_id'] !== ''
            && $credentials['client_secret'] !== ''
            && $credentials['username'] !== ''
            && $credentials['password'] !== '';
    }

    /**
     * @return array{client_id:string,client_secret:string,username:string,password:string}
     */
    private function resolvePathaoCredentials(Courierapi $pathao): array
    {
        $storedCredentials = $this->pathaoCredentialsFromCourier($pathao);
        if ($this->pathaoCredentialsAreComplete($storedCredentials)) {
            return $storedCredentials;
        }

        $secureCredentials = $this->pathaoCredentialsFromEnvironment();
        if ($this->pathaoCredentialsAreComplete($secureCredentials)) {
            return $secureCredentials;
        }

        throw ValidationException::withMessages([
            'pathao' => ['Pathao credentials are missing in backend configuration.'],
        ]);
    }

    private function pathaoDefaultBaseUrl(): string
    {
        $configured = trim((string) config('services.pathao.base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim(self::PATHAO_DEFAULT_BASE_URL, '/');
    }

    private function normalizePathaoLocationValue(mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (is_numeric($normalized)) {
            return (int) $normalized;
        }

        return $normalized;
    }

    private function resolvePathaoStoreId(Courierapi $pathao, string $token, mixed $overrideStoreId = null): ?string
    {
        $candidates = [
            $overrideStoreId,
            config('services.pathao.store_id'),
            $pathao->api_key ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim((string) ($candidate ?? ''));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        $resolvedFromApi = $this->fetchPathaoStoreIdFromApi($pathao, $token);
        if ($resolvedFromApi !== null && trim((string) ($pathao->api_key ?? '')) === '') {
            $pathao->api_key = $resolvedFromApi;
            $pathao->save();
        }

        return $resolvedFromApi;
    }

    private function fetchPathaoStoreIdFromApi(Courierapi $pathao, string $token): ?string
    {
        try {
            $stores = $this->fetchPathaoStoreOptionsFromApi($pathao, $token);
            $firstStore = $stores[0]['id'] ?? null;
            if ($firstStore === null) {
                return null;
            }

            return trim((string) $firstStore) ?: null;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    private function fetchPathaoStoreOptionsFromApi(Courierapi $pathao, string &$token): array
    {
        $response = $this->requestPathaoResourceWithFallback(
            $pathao,
            $token,
            $this->resolvePathaoStoresEndpoints((string) ($pathao->url ?? ''))
        );

        if (!$response || !$response->successful()) {
            throw ValidationException::withMessages([
                'pathao' => [$response
                    ? $this->resolveCourierErrorMessage($response, 'Unable to load Pathao stores.')
                    : 'Unable to load Pathao stores.'],
            ]);
        }

        $stores = $this->normalizePathaoOptionCollection(
            $this->extractPathaoCollection($response->json()),
            ['store_id', 'id'],
            ['store_name', 'name', 'title']
        );

        if (!empty($stores)) {
            return $stores;
        }

        $fallbackStoreId = $this->extractPathaoStoreIdFromResponse($response);
        if ($fallbackStoreId !== null) {
            return [[
                'id' => $this->normalizePathaoLocationValue($fallbackStoreId),
                'name' => 'Store ' . $fallbackStoreId,
            ]];
        }

        return [];
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    private function fetchPathaoCityOptionsFromApi(Courierapi $pathao, string &$token): array
    {
        $response = $this->requestPathaoResourceWithFallback(
            $pathao,
            $token,
            $this->resolvePathaoCityEndpoints((string) ($pathao->url ?? ''))
        );

        if (!$response || !$response->successful()) {
            throw ValidationException::withMessages([
                'pathao' => [$response
                    ? $this->resolveCourierErrorMessage($response, 'Unable to load Pathao cities.')
                    : 'Unable to load Pathao cities.'],
            ]);
        }

        $cities = $this->normalizePathaoOptionCollection(
            $this->extractPathaoCollection($response->json()),
            ['city_id', 'id'],
            ['city_name', 'name']
        );

        if (!empty($cities)) {
            return $cities;
        }

        throw ValidationException::withMessages([
            'pathao' => ['Pathao city list is empty or invalid.'],
        ]);
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    private function fetchPathaoZoneOptionsFromApi(Courierapi $pathao, string &$token, int $cityId): array
    {
        $response = $this->requestPathaoResourceWithFallback(
            $pathao,
            $token,
            $this->resolvePathaoZoneEndpoints((string) ($pathao->url ?? ''), $cityId)
        );

        if (!$response || !$response->successful()) {
            throw ValidationException::withMessages([
                'pathao' => [$response
                    ? $this->resolveCourierErrorMessage($response, 'Unable to load Pathao zones.')
                    : 'Unable to load Pathao zones.'],
            ]);
        }

        $zones = $this->normalizePathaoOptionCollection(
            $this->extractPathaoCollection($response->json()),
            ['zone_id', 'id'],
            ['zone_name', 'name']
        );

        return $zones;
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    private function fetchPathaoAreaOptionsFromApi(Courierapi $pathao, string &$token, int $zoneId): array
    {
        $response = $this->requestPathaoResourceWithFallback(
            $pathao,
            $token,
            $this->resolvePathaoAreaEndpoints((string) ($pathao->url ?? ''), $zoneId)
        );

        if (!$response || !$response->successful()) {
            throw ValidationException::withMessages([
                'pathao' => [$response
                    ? $this->resolveCourierErrorMessage($response, 'Unable to load Pathao areas.')
                    : 'Unable to load Pathao areas.'],
            ]);
        }

        $areas = $this->normalizePathaoOptionCollection(
            $this->extractPathaoCollection($response->json()),
            ['area_id', 'id'],
            ['area_name', 'name']
        );

        return $areas;
    }

    private function requestPathaoResourceWithFallback(Courierapi $pathao, string &$token, array $endpoints): ?Response
    {
        $lastResponse = null;
        $normalizedEndpoints = array_values(array_unique(array_filter(array_map(
            fn ($endpoint) => trim((string) $endpoint),
            $endpoints
        ))));

        foreach ($normalizedEndpoints as $endpoint) {
            try {
                $response = Http::acceptJson()->withToken($token)->get($endpoint);

                if ($response->status() === 401) {
                    $token = $this->resolvePathaoAccessToken($pathao, true);
                    $response = Http::acceptJson()->withToken($token)->get($endpoint);
                }

                if ($response->successful()) {
                    return $response;
                }

                $lastResponse = $response;
            } catch (Throwable $exception) {
                continue;
            }
        }

        return $lastResponse;
    }

    /**
     * @return array<int, string>
     */
    private function resolvePathaoStoresEndpoints(string $configuredUrl = ''): array
    {
        $apiRoot = $this->pathaoApiRootFromConfiguredUrl($configuredUrl);

        return [
            $this->resolvePathaoStoresEndpoint($configuredUrl),
            $apiRoot . '/stores',
            $apiRoot . '/store',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolvePathaoCityEndpoints(string $configuredUrl = ''): array
    {
        $apiRoot = $this->pathaoApiRootFromConfiguredUrl($configuredUrl);

        return [
            $apiRoot . '/city-list',
            $apiRoot . '/cities',
            $apiRoot . '/city_list',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolvePathaoZoneEndpoints(string $configuredUrl, int $cityId): array
    {
        $apiRoot = $this->pathaoApiRootFromConfiguredUrl($configuredUrl);
        $cityId = max(1, $cityId);

        return [
            $apiRoot . '/cities/' . $cityId . '/zone-list',
            $apiRoot . '/cities/' . $cityId . '/zones',
            $apiRoot . '/zones?city_id=' . $cityId,
            $apiRoot . '/zone-list/' . $cityId,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolvePathaoAreaEndpoints(string $configuredUrl, int $zoneId): array
    {
        $apiRoot = $this->pathaoApiRootFromConfiguredUrl($configuredUrl);
        $zoneId = max(1, $zoneId);

        return [
            $apiRoot . '/zones/' . $zoneId . '/area-list',
            $apiRoot . '/zones/' . $zoneId . '/areas',
            $apiRoot . '/areas?zone_id=' . $zoneId,
            $apiRoot . '/area-list/' . $zoneId,
        ];
    }

    private function resolvePathaoStoresEndpoint(string $configuredUrl = ''): string
    {
        $candidate = trim($configuredUrl);
        if ($candidate === '') {
            return $this->pathaoDefaultBaseUrl() . self::PATHAO_STORES_PATH;
        }

        if (preg_match('/\/issue-token\/?$/i', $candidate)) {
            $candidate = preg_replace('/\/issue-token\/?$/i', '', $candidate) ?: $candidate;
            return rtrim($candidate, '/') . '/stores';
        }

        if (preg_match('/\/orders\/?$/i', $candidate)) {
            $candidate = preg_replace('/\/orders\/?$/i', '', $candidate) ?: $candidate;
            return rtrim($candidate, '/') . '/stores';
        }

        if (preg_match('/\/stores\/?$/i', $candidate)) {
            return rtrim($candidate, '/');
        }

        return rtrim($candidate, '/') . self::PATHAO_STORES_PATH;
    }

    private function pathaoApiRootFromConfiguredUrl(string $configuredUrl = ''): string
    {
        $candidate = trim($configuredUrl);
        if ($candidate === '') {
            return $this->pathaoDefaultBaseUrl() . '/aladdin/api/v1';
        }

        if (preg_match('/\/aladdin\/api\/v1/i', $candidate)) {
            return rtrim((string) preg_replace('/\/aladdin\/api\/v1.*$/i', '/aladdin/api/v1', $candidate), '/');
        }

        return rtrim($candidate, '/') . '/aladdin/api/v1';
    }

    private function extractPathaoStoreIdFromResponse(Response $response): ?string
    {
        $body = $response->json();
        $candidates = [
            data_get($body, 'data.store_id'),
            data_get($body, 'store_id'),
            data_get($body, 'data.data.0.store_id'),
            data_get($body, 'data.data.0.id'),
            data_get($body, 'data.0.store_id'),
            data_get($body, 'data.0.id'),
            data_get($body, 'stores.0.store_id'),
            data_get($body, 'stores.0.id'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private function extractPathaoCollection(mixed $payload): array
    {
        if (!is_array($payload) || empty($payload)) {
            return [];
        }

        $directPaths = [
            'data.data',
            'data.items',
            'data.stores',
            'data.cities',
            'data.zones',
            'data.areas',
            'result.data',
            'result.items',
            'stores',
            'cities',
            'zones',
            'areas',
            'data',
            'result',
        ];

        foreach ($directPaths as $path) {
            $candidate = data_get($payload, $path);
            if (is_array($candidate) && array_is_list($candidate)) {
                return $candidate;
            }
        }

        return $this->findFirstListInPathaoPayload($payload) ?? [];
    }

    /**
     * @return array<int, mixed>|null
     */
    private function findFirstListInPathaoPayload(mixed $value): ?array
    {
        if (!is_array($value) || empty($value)) {
            return null;
        }

        if (array_is_list($value)) {
            return $value;
        }

        foreach ($value as $item) {
            $found = $this->findFirstListInPathaoPayload($item);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $items
     * @param array<int, string> $idKeys
     * @param array<int, string> $nameKeys
     * @return array<int, array{id:int|string,name:string}>
     */
    private function normalizePathaoOptionCollection(array $items, array $idKeys, array $nameKeys): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $idValue = $this->normalizePathaoLocationValue($this->pickPathaoValue($item, $idKeys));
            $nameValue = trim((string) $this->pickPathaoValue($item, $nameKeys));

            if ($idValue === null || $nameValue === '') {
                continue;
            }

            $normalized[(string) $idValue] = [
                'id' => $idValue,
                'name' => $nameValue,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, string> $keys
     */
    private function pickPathaoValue(array $item, array $keys): mixed
    {
        foreach ($keys as $key) {
            $candidate = data_get($item, $key);
            if ($candidate === null) {
                continue;
            }

            if (is_string($candidate) && trim($candidate) === '') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function fetchPathaoStatus(Order $order, Courierapi $pathao): ?string
    {
        $courierOrderId = trim((string) ($order->courier_order_id ?? ''));
        if ($courierOrderId === '') {
            return null;
        }

        $token = $this->resolvePathaoAccessToken($pathao, false);
        $endpoint = $this->resolvePathaoStatusEndpoint((string) ($pathao->url ?? ''), $courierOrderId);

        $response = Http::acceptJson()->withToken($token)->get($endpoint);
        if ($response->status() === 401) {
            $token = $this->resolvePathaoAccessToken($pathao, true);
            $response = Http::acceptJson()->withToken($token)->get($endpoint);
        }

        if (!$response->successful()) {
            throw ValidationException::withMessages([
                'pathao' => [$this->resolveCourierErrorMessage($response, 'Pathao status sync failed.')],
            ]);
        }

        $rawStatus = $response->json('data.delivery_status')
            ?? $response->json('data.status')
            ?? $response->json('status')
            ?? $response->json('data.order_status');

        if ($rawStatus === null) {
            return null;
        }

        return strtolower(trim((string) $rawStatus));
    }

    private function resolvePathaoStatusEndpoint(string $configuredUrl, string $courierOrderId): string
    {
        $candidate = trim($configuredUrl);
        if ($candidate === '') {
            return rtrim($this->pathaoDefaultBaseUrl(), '/') . '/aladdin/api/v1/orders/' . rawurlencode($courierOrderId);
        }

        if (str_contains($candidate, '%s')) {
            return sprintf($candidate, rawurlencode($courierOrderId));
        }

        $normalized = rtrim($candidate, '/');
        if (preg_match('/\/orders\/[^\/]+$/i', $normalized)) {
            return $normalized;
        }

        if (preg_match('/\/orders$/i', $normalized)) {
            return $normalized . '/' . rawurlencode($courierOrderId);
        }

        return $normalized . '/aladdin/api/v1/orders/' . rawurlencode($courierOrderId);
    }

    private function countOrdersByCanonicalStatus(string $canonicalKey): int
    {
        $filter = $this->orderStatusService->filterValuesForRoute($canonicalKey);
        $ids = $filter['ids'] ?? [];
        $rawValues = $filter['raw_values'] ?? [];

        return (int) Order::query()
            ->where(function ($query) use ($ids, $rawValues) {
                if (!empty($ids)) {
                    $query->whereIn('order_status', $ids);
                }

                if (!empty($rawValues)) {
                    if (!empty($ids)) {
                        $query->orWhereIn(DB::raw('LOWER(TRIM(order_status))'), $rawValues);
                    } else {
                        $query->whereIn(DB::raw('LOWER(TRIM(order_status))'), $rawValues);
                    }
                }
            })
            ->count();
    }

    private function generateInvoiceId(): string
    {
        do {
            $candidate = (string) random_int(100000, 999999);
        } while (Order::query()->where('invoice_id', $candidate)->exists());

        return $candidate;
    }

    private function calculateOrderSubTotal(int $orderId): int
    {
        $items = OrderDetails::query()
            ->select(['sale_price', 'qty'])
            ->where('order_id', $orderId)
            ->get();

        return (int) $items->sum(function ($item) {
            return ((int) $item->sale_price) * ((int) $item->qty);
        });
    }

    /**
     * Get status name
     */
    private function getStatusName($status)
    {
        return $this->orderStatusService->labelForValue($status);
    }
}
