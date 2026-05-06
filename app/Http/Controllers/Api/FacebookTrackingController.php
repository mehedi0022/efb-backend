<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FacebookCAPIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacebookTrackingController extends Controller
{
    public function __construct(
        private FacebookCAPIService $capi
    ) {}

    public function trackPurchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id'  => 'required|string|max:255',
            'order_id'  => 'required|string|max:255',
            'value'     => 'required|numeric|min:0',
            'currency'  => 'nullable|string|size:3',
            'item_ids'  => 'nullable|array',
            'item_ids.*'=> 'string',
            'quantity'  => 'nullable|integer|min:1',
            'fbp'       => 'nullable|string',
            'fbc'       => 'nullable|string',
        ]);

        $this->capi->sendPurchaseEvent([
            ...$validated,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url'        => $request->header('Referer', ''),
            'email'      => $request->user()?->email,
            'phone'      => $request->user()?->phone,
        ]);
        return response()->json(['status' => 'ok']);
    }
}