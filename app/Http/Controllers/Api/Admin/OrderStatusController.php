<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderStatus;
use App\Services\OrderStatusService;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
    public function __construct(private readonly OrderStatusService $orderStatusService)
    {
    }

    public function index(Request $request)
    {
        $this->orderStatusService->ensureDefaultStatuses();

        $query = OrderStatus::orderByDesc('id');

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where('name', 'LIKE', "%{$keyword}%");
        }

        if ($request->filled('page') || $request->filled('per_page') || $request->filled('keyword')) {
            $perPage = (int) $request->get('per_page', 20);
            $items = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $items->items(),
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'from' => $items->firstItem(),
                    'to' => $items->lastItem(),
                ],
            ]);
        }

        $statuses = $query->get();

        return response()->json([
            'success' => true,
            'data' => $statuses,
        ]);
    }

    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order statuses are system-managed and cannot be created manually.',
        ], 422);
    }

    public function update(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order statuses are system-managed and cannot be edited manually.',
        ], 422);
    }

    public function updateStatus(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order statuses are system-managed and cannot be toggled manually.',
        ], 422);
    }

    public function destroy(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order statuses are system-managed and cannot be deleted manually.',
        ], 422);
    }
}
