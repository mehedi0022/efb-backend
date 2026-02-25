<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderDetails;
use App\Models\Customer;
use App\Models\District;
use App\Models\Product;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Shipping;
use App\Models\ShippingCharge;
use App\Models\User;
use App\Services\ApiClient;
use App\Services\OrderStatusService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * Cache resolved product SKUs while processing dropshipping payloads.
     *
     * @var array<int, string>
     */
    private array $dropshippingProductSkuCache = [];

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
            'discount' => 'nullable|integer|min:0',
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
            $defaultStatusId = $this->orderStatusService->resolveStatusId('pending') ?: 1;
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
                $discount = max(0, (int) ($validated['discount'] ?? 0));

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
                    'discount' => $discount,
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
                $order->amount = max(0, $subTotal + $shippingCharge - $discount);
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
            'discount' => 'nullable|integer|min:0',
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
            if (array_key_exists('discount', $validated)) {
                $order->discount = (int) $validated['discount'];
            }
            if (array_key_exists('note', $validated)) {
                $order->note = $validated['note'];
            }
            if (array_key_exists('admin_note', $validated)) {
                $order->admin_note = $validated['admin_note'];
            }

            $subTotal = $this->calculateOrderSubTotal($order->id);
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
                'pending' => $this->countOrdersByCanonicalStatus('pending'),
                'processing' => $this->countOrdersByCanonicalStatus('processing'),
                'confirmed' => $this->countOrdersByCanonicalStatus('confirmed'),
                'delivered' => $this->countOrdersByCanonicalStatus('delivered'),
                'cancelled' => $this->countOrdersByCanonicalStatus('cancelled'),
                'complete' => $this->countOrdersByCanonicalStatus('complete'),
                'returned' => $this->countOrdersByCanonicalStatus('returned'),
                'hold' => $this->countOrdersByCanonicalStatus('hold'),
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

            $payload = $this->buildDropshippingPayload($order, $sellerCode);
            $itemErrors = $payload['item_errors'] ?? [];
            unset($payload['item_errors']);

            if (!empty($itemErrors)) {
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => $itemErrors[0],
                ];
                continue;
            }

            if (empty($payload['items'])) {
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                    'message' => 'No order items found for this order.',
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
                    $order->save();
                }

                $sentOrders[] = [
                    'order_id' => (int) $order->id,
                    'invoice_id' => $order->invoice_id,
                ];
                continue;
            }

            $failedMessage = $response->json('message');
            if (!is_string($failedMessage) || trim($failedMessage) === '') {
                $failedMessage = trim((string) $response->body()) ?: 'Dropshipping request failed.';
            }

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
     * Send orders to Pathao (stubbed)
     */
    public function sendToPathao(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Courier request queued',
        ]);
    }

    /**
     * Print orders (basic HTML response)
     */
    public function printOrders(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array',
        ]);

        $orders = Order::with(['orderdetails', 'shipping', 'payment'])->whereIn('id', $request->order_ids)->get();

        $html = '<html><head><title>Orders Print</title><style>body{font-family:Arial, sans-serif;}table{width:100%;border-collapse:collapse;margin-bottom:24px;}th,td{border:1px solid #ddd;padding:8px;font-size:12px;}th{background:#f5f5f5;}</style></head><body>';
        foreach ($orders as $order) {
            $html .= '<h3>Invoice #' . $order->invoice_id . '</h3>';
            $html .= '<p><strong>Customer:</strong> ' . ($order->shipping->name ?? '') . ' | ' . ($order->shipping->phone ?? '') . '</p>';
            $html .= '<table><thead><tr><th>Product</th><th>Qty</th><th>Price</th></tr></thead><tbody>';
            $subTotal = 0;
            foreach ($order->orderdetails as $detail) {
                $lineTotal = ($detail->sale_price ?? 0) * ($detail->qty ?? 0);
                $subTotal += $lineTotal;
                $html .= '<tr><td>' . ($detail->product_name ?? '') . '</td><td>' . ($detail->qty ?? 0) . '</td><td>' . $lineTotal . '</td></tr>';
            }
            $html .= '<tr><td colspan=\"2\">Shipping</td><td>' . ($order->shipping_charge ?? 0) . '</td></tr>';
            $html .= '<tr><td colspan=\"2\">Discount</td><td>' . ($order->discount ?? 0) . '</td></tr>';
            $html .= '<tr><td colspan=\"2\"><strong>Total</strong></td><td><strong>' . ($subTotal + ($order->shipping_charge ?? 0) - ($order->discount ?? 0)) . '</strong></td></tr>';
            $html .= '</tbody></table>';
        }
        $html .= '</body></html>';

        return response()->json(['success' => true, 'view' => $html]);
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
            'ip_address' => $order->ip_address,
            'order_status' => $order->order_status,
            'status_name' => $this->getStatusName($order->order_status),
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
