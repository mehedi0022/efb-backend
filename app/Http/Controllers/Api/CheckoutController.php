<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\IncompleteOrder;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(protected OrderService $orderService)
    {
    }

    public function placeOrder(StoreOrderRequest $request): JsonResponse
    {
        $cartId = $request->header('X-Cart-ID');
        if (!$cartId) {
            return $this->errorResponse('Cart ID required.', 400);
        }

        try {
            $order = $this->orderService->createOrder(
                $request->validated(),
                $cartId,
                $request->ip()
            );

            IncompleteOrder::query()
                ->where('cart_id', $cartId)
                ->whereRaw("LOWER(COALESCE(status, '')) != 'completed'")
                ->update(['status' => 'completed']);

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'order_id' => $order->id,
                'invoice_id' => $order->invoice_id,
                'amount' => $order->amount,
                'payment_url' => $this->getPaymentUrl($order, $request->payment_method),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            if ($exception instanceof ValidationException) {
                return $this->errorResponse(
                    $exception->getMessage() ?: 'Order validation failed.',
                    422,
                    $exception->errors()
                );
            }

            if ($exception instanceof QueryException) {
                $errorCode = (string) ($exception->errorInfo[1] ?? '');
                $message = Str::lower((string) $exception->getMessage());

                if ($errorCode === '1146' || str_contains($message, 'base table or view not found')) {
                    return $this->errorResponse(
                        'Orders table is missing in the configured database. Please run migrations.',
                        500
                    );
                }

                return $this->errorResponse('Database error while placing order.', 500);
            }

            return $this->errorResponse('Unable to place order right now. Please try again.', 400);
        }
    }

    protected function getPaymentUrl($order, $method): ?string
    {
        if ($method === 'bkash') {
            return "/bkash/checkout-url/create?order_id={$order->id}"; // Placeholder
        }
        if ($method === 'shurjopay') {
            // Should call shurjopay service
             return "/shurjopay/checkout?order_id={$order->id}"; // Placeholder
        }
        return null; // COD
    }
}
