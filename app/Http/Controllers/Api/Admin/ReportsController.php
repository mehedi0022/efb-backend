<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderDetails;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    public function orderReport(Request $request)
    {
        $query = OrderDetails::with(['shipping', 'order'])->whereHas('order', function ($q) {
            $q->where('order_status', 6);
        });

        if ($request->filled('keyword')) {
            $query->where('product_name', 'LIKE', '%' . $request->keyword . '%');
        }

        if ($request->filled('user_id')) {
            $query->whereHas('order', function ($q) use ($request) {
                $q->where('user_id', $request->user_id);
            });
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('updated_at', [$request->start_date, $request->end_date]);
        }

        $totalPurchase = (clone $query)->sum(DB::raw('purchase_price * qty'));
        $additionalCost = (clone $query)->sum(DB::raw('additional_cost * qty'));
        $totalItem = (clone $query)->sum('qty');
        $totalSales = (clone $query)->sum(DB::raw('sale_price * qty'));

        $perPage = (int) $request->get('per_page', 20);
        $orders = $query->paginate($perPage);

        $users = User::select('id', 'name')->get();

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
            'summary' => [
                'total_purchase' => $totalPurchase,
                'additional_cost' => $additionalCost,
                'total_item' => $totalItem,
                'total_sales' => $totalSales,
            ],
            'users' => $users,
        ]);
    }
}
