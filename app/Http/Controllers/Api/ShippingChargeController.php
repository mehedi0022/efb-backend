<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingCharge;
use Illuminate\Http\Request;

class ShippingChargeController extends Controller
{
    public function index(Request $request)
    {
        $charges = ShippingCharge::where('status', 1)
            ->orderBy('id', 'ASC')
            ->get(['id', 'name', 'amount']);

        return response()->json([
            'success' => true,
            'data' => $charges,
        ]);
    }
}
