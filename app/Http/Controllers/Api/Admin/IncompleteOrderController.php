<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IncompleteOrder;
use App\Models\District;
use App\Models\Order;
use App\Models\OrderDetails;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipping;
use App\Models\ShippingCharge;
use App\Services\OrderStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncompleteOrderController extends Controller
{
    public function __construct(private readonly OrderStatusService $orderStatusService)
    {
    }

    public function index(Request $request)
    {
        $query = IncompleteOrder::orderByDesc('id');

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->keyword);
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('name', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('phone', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('address', 'LIKE', '%' . $keyword . '%');
            });
        }

        if ($request->filled('status') && strtolower((string) $request->status) !== 'all') {
            $query->where('status', (string) $request->status);
        } else {
            $query->whereRaw("LOWER(COALESCE(status, '')) != 'completed'");
        }

        $perPage = (int) $request->get('per_page', 20);
        $orders = $query->paginate($perPage);

        $data = $orders->items();
        foreach ($data as $item) {
            $item->cart_products = $this->decodeCartProducts($item->cart_data);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
        ]);
    }

    public function meta()
    {
        $shippingCharges = ShippingCharge::where('status', 1)->select('id', 'name', 'amount')->get();
        $districts = District::select('id', 'name')->get();

        return response()->json([
            'success' => true,
            'shipping_charges' => $shippingCharges,
            'districts' => $districts,
        ]);
    }

    public function show($id)
    {
        $order = IncompleteOrder::findOrFail($id);
        $cartProducts = $this->decodeCartProducts($order->cart_data);

        return response()->json([
            'success' => true,
            'data' => $order,
            'cart_products' => $cartProducts,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required',
            'phone' => 'required',
            'address' => 'required',
        ]);

        $data = $request->all();
        $data['status'] = $request->status ?? 'Incomplete';
        $order = IncompleteOrder::create($data);

        return response()->json(['success' => true, 'data' => $order], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required',
            'phone' => 'required',
            'address' => 'required',
        ]);

        $order = IncompleteOrder::findOrFail($id);
        $order->update($request->all());

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function destroy($id)
    {
        $order = IncompleteOrder::findOrFail($id);
        $order->delete();

        return response()->json(['success' => true]);
    }

    public function updateQty(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'row_id' => 'required|string',
            'qty' => 'required|integer|min:1',
        ]);

        $order = IncompleteOrder::findOrFail($request->order_id);
        $decoded = json_decode($order->cart_data, true) ?: [];

        if (isset($decoded[$request->row_id])) {
            $decoded[$request->row_id]['qty'] = $request->qty;
        }

        $subtotal = $this->calculateSubtotal($decoded);

        $order->cart_data = json_encode($decoded);
        $order->save();

        $shipping = $this->resolveShippingAmount($order->shipping_charge_id);
        $discount = $order->discount ?? 0;
        $grandTotal = $subtotal + $shipping - $discount;

        return response()->json([
            'success' => true,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'discount' => $discount,
            'grand_total' => $grandTotal,
        ]);
    }

    public function updateShipping(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'shipping_charge_id' => 'required|integer',
        ]);

        $order = IncompleteOrder::findOrFail($request->order_id);
        $order->shipping_charge_id = $request->shipping_charge_id;
        $order->save();

        $decoded = json_decode($order->cart_data, true) ?: [];
        $subtotal = $this->calculateSubtotal($decoded);

        $shipping = $this->resolveShippingAmount($request->shipping_charge_id);
        $discount = $order->discount ?? 0;
        $grandTotal = $subtotal + $shipping - $discount;

        return response()->json([
            'success' => true,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'discount' => $discount,
            'grand_total' => $grandTotal,
        ]);
    }

    public function createOrder(Request $request, $id)
    {
        $request->validate([
            'name' => 'required',
            'phone' => 'required',
            'address' => 'required',
            'area' => 'required|integer',
            'district' => 'required',
        ]);

        $incompleteOrder = IncompleteOrder::findOrFail($id);
        $this->orderStatusService->ensureDefaultStatuses();
        $newOrderStatusId = $this->orderStatusService->resolveStatusId('new-order') ?: 1;

        return DB::transaction(function () use ($request, $incompleteOrder, $newOrderStatusId) {
            $decoded = json_decode($incompleteOrder->cart_data, true) ?: [];
            $items = [];
            $subtotal = 0;

            foreach ($decoded as $rowId => $item) {
                if (is_string($item)) {
                    $item = json_decode($item, true);
                }
                if (!is_array($item)) {
                    continue;
                }

                $price = (float) ($item['price'] ?? 0);
                $qty = (int) ($item['qty'] ?? 1);

                $subtotal += $price * $qty;
                $items[] = $item;
            }

            $discount = $incompleteOrder->discount ?? 0;
            $shippingCharge = ShippingCharge::findOrFail($request->area);

            $customer = Customer::where('phone', $request->phone)->select('id', 'phone')->first();
            if (!$customer) {
                $password = rand(111111, 999999);
                $customer = Customer::create([
                    'name' => $request->name,
                    'slug' => $request->name,
                    'phone' => $request->phone,
                    'email' => $request->email ?: 'N/A',
                    'password' => bcrypt($password),
                    'ip_address' => $request->ip(),
                    'verify' => 1,
                    'status' => 'active',
                ]);
            }

            $order = Order::create([
                'invoice_id' => rand(11111, 99999),
                'amount' => ($subtotal + $shippingCharge->amount) - ($discount ?? 0),
                'discount' => $discount ?? 0,
                'shipping_charge' => $shippingCharge->amount,
                'customer_id' => $customer->id,
                'district' => $request->district,
                'order_status' => (string) $newOrderStatusId,
                'note' => $request->note,
                'ip_address' => $request->ip(),
            ]);

            Shipping::create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'name' => $request->name,
                'phone' => $request->phone,
                'address' => $request->address,
                'area' => $shippingCharge->name,
                'ip_address' => $request->ip(),
            ]);

            Payment::create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'payment_method' => 'Cash On Delivery',
                'amount' => $order->amount,
                'payment_status' => 'pending',
            ]);

            $productSkuMap = collect($items)
                ->map(function ($item) {
                    if (!is_array($item)) {
                        return null;
                    }

                    $productId = $item['id'] ?? $item['product_id'] ?? null;
                    if (!is_numeric($productId)) {
                        return null;
                    }

                    return (int) $productId;
                })
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->whenNotEmpty(function ($productIds) {
                    return Product::query()
                        ->whereIn('id', $productIds->all())
                        ->pluck('product_code', 'id');
                }, fn () => collect())
                ->map(fn ($value) => trim((string) $value))
                ->all();

            foreach ($items as $item) {
                if (is_string($item)) {
                    $item = json_decode($item, true);
                }
                if (!is_array($item)) {
                    continue;
                }

                $options = $item['options'] ?? [];
                $options['product_size_id'] = $options['product_size_id'] ?? $item['product_size_id'] ?? null;
                $options['product_color_id'] = $options['product_color_id'] ?? $item['product_color_id'] ?? null;
                $options['product_size'] = $options['product_size'] ?? $item['product_size'] ?? null;
                $options['product_color'] = $options['product_color'] ?? $item['product_color'] ?? null;
                $options['image'] = $options['image'] ?? $item['image'] ?? null;

                $rawProductId = $item['id'] ?? $item['product_id'] ?? null;
                $productId = is_numeric($rawProductId)
                    ? (int) $rawProductId
                    : null;
                $productSku = trim((string) (
                    $options['sku']
                    ?? $options['product_sku']
                    ?? $item['sku']
                    ?? $item['product_sku']
                    ?? ($productId ? ($productSkuMap[$productId] ?? '') : '')
                    ?? ''
                ));
                if ($productSku === '' && $productId) {
                    $productSku = trim((string) (
                        Product::query()
                            ->where('id', $productId)
                            ->value('product_code')
                        ?? ''
                    ));
                }
                if ($productId && $productSku === '') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'cart_data' => ["Missing SKU for product ID {$productId} in incomplete order."],
                    ]);
                }
                if ($productSku === '') {
                    $productSku = null;
                }

                OrderDetails::create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'product_name' => $item['name'] ?? null,
                    'purchase_price' => $options['purchase_price'] ?? 0,
                    'product_discount' => $options['product_discount'] ?? 0,
                    'sale_price' => $item['price'] ?? 0,
                    'product_size_id' => $options['product_size_id'],
                    'product_color_id' => $options['product_color_id'],
                    'product_size' => $options['product_size'],
                    'product_color' => $options['product_color'],
                    'image' => $options['image'],
                    'image_color' => $item['image_color'] ?? null,
                    'photos' => $item['photos'] ?? null,
                    'order_note' => $options['order_note'] ?? null,
                    'writing' => $options['writing'] ?? null,
                    'product_sku' => $productSku,
                    'qty' => $item['qty'] ?? 1,
                ]);
            }

            $incompleteOrder->status = 'completed';
            $incompleteOrder->save();

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'invoice_id' => $order->invoice_id,
            ]);
        });
    }

    private function decodeCartProducts(?string $cartData): array
    {
        if (empty($cartData)) {
            return [];
        }

        $decoded = json_decode($cartData, true) ?: [];
        $cartProducts = [];

        foreach ($decoded as $rowId => $item) {
            if (is_string($item)) {
                $item = json_decode($item, true);
            }
            if (!is_array($item)) {
                continue;
            }

            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $image = $item['image']
                ?? $item['product_image']
                ?? ($options['image'] ?? null)
                ?? ($item['product']['image']['image'] ?? null)
                ?? ($item['product']['thumbnail'] ?? null);

            $item['row_id'] = $rowId;
            $item['image'] = $image;
            $item['product_size'] = $item['product_size'] ?? ($options['product_size'] ?? null);
            $item['product_color'] = $item['product_color'] ?? ($options['product_color'] ?? null);
            $cartProducts[] = $item;
        }

        return $cartProducts;
    }

    private function calculateSubtotal(array $decoded): float
    {
        $subtotal = 0;
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $item = json_decode($item, true);
            }
            if (!is_array($item)) {
                continue;
            }

            $price = (float) ($item['price'] ?? 0);
            $qty = (int) ($item['qty'] ?? 1);
            $subtotal += $price * $qty;
        }

        return $subtotal;
    }

    private function resolveShippingAmount(?int $shippingChargeId): float
    {
        if (!$shippingChargeId) {
            return 0;
        }

        $shippingCharge = ShippingCharge::find($shippingChargeId);
        return $shippingCharge ? (float) $shippingCharge->amount : 0;
    }
}
