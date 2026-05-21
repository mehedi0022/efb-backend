<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function __construct(protected CartService $cartService)
    {
    }

    public function getCart(Request $request): JsonResponse
    {
        $cartId = $request->header('X-Cart-ID');
        $cart = $this->cartService->getCart($cartId ?: '', null);

        return response()->json([
            'success' => true,
            'message' => 'Cart fetched successfully.',
            'data' => $cart,
            'cart_id' => $cart->id,
        ]);
    }

    public function addToCart(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'options' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 422, $validator->errors());
        }

        $cartId = $request->header('X-Cart-ID');
        
        // Ensure we have a cart
        $cart = $this->cartService->getCart($cartId ?: '', null);

        try {
            $cart = $this->cartService->addItem(
                $cart->id,
                $request->product_id,
                $request->quantity,
                $request->options ?? []
            );
        } catch (ValidationException $exception) {
            return $this->errorResponse(
                $exception->getMessage() ?: 'Invalid cart item options.',
                422,
                $exception->errors()
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart.',
            'data' => $cart,
            'cart_id' => $cart->id,
        ]);
    }

    public function addExternal(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'external_product_id' => 'required|string',
            'product_name' => 'required|string',
            'product_image' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:1',
            'options' => 'nullable|array',
            'options.panel_product_id' => 'required|integer|min:1',
            'options.panel_variant_id' => 'required|integer|min:1',
            'options.panel_seller_product_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 422, $validator->errors());
        }

        $cartId = $request->header('X-Cart-ID');
        $cart = $this->cartService->getCart($cartId ?: '', null);

        $cart = $this->cartService->addExternalItem($cart->id, $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart.',
            'data' => $cart,
            'cart_id' => $cart->id,
        ]);
    }

    public function updateItem(Request $request, int $itemId): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:0',
        ]);

        $cartId = $request->header('X-Cart-ID');
        if (!$cartId) {
            return $this->errorResponse('Cart ID required.', 400);
        }

        $cart = $this->cartService->updateItem($cartId, $itemId, $request->quantity);

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated.',
            'data' => $cart,
        ]);
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        $cartId = $request->header('X-Cart-ID');
        if (!$cartId) {
            return $this->errorResponse('Cart ID required.', 400);
        }

        $cart = $this->cartService->removeItem($cartId, $itemId);

        return response()->json([
            'success' => true,
            'message' => 'Cart item removed.',
            'data' => $cart,
        ]);
    }

    public function clearCart(Request $request): JsonResponse
    {
        $cartId = $request->header('X-Cart-ID');
        if (!$cartId) {
            return $this->errorResponse('Cart ID required.', 400);
        }

        $this->cartService->clearCart($cartId);

        return $this->successResponse(null, 'Cart cleared.');
    }
}
