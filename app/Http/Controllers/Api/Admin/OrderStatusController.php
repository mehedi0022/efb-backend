<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderStatus;
use App\Services\OrderStatusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $validated = $request->validate([
            'name' => 'required|string|max:155',
            'status' => 'required|in:0,1',
        ]);

        $slug = $this->orderStatusService->normalizeSlug((string) $validated['name']);
        if ($slug === '') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status name.',
            ], 422);
        }

        $duplicate = OrderStatus::query()
            ->whereRaw('LOWER(slug) = ?', [$slug])
            ->exists();
        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'A status with the same slug already exists.',
            ], 422);
        }

        $status = OrderStatus::create([
            'name' => trim((string) $validated['name']),
            'slug' => $slug,
            'status' => (string) ((int) $validated['status']),
        ]);

        return response()->json([
            'success' => true,
            'data' => $status,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $status = OrderStatus::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:155',
            'status' => 'required|in:0,1',
        ]);

        $slug = $this->orderStatusService->normalizeSlug((string) $validated['name']);
        if ($slug === '') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status name.',
            ], 422);
        }

        $duplicate = OrderStatus::query()
            ->whereRaw('LOWER(slug) = ?', [$slug])
            ->where('id', '!=', (int) $status->id)
            ->exists();
        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'A status with the same slug already exists.',
            ], 422);
        }

        $status->update([
            'name' => trim((string) $validated['name']),
            'slug' => $slug,
            'status' => (string) ((int) $validated['status']),
        ]);

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => ['required', 'integer', Rule::exists('order_statuses', 'id')],
            'status' => 'required|in:0,1',
        ]);

        OrderStatus::whereIn('id', $request->ids)->update(['status' => (string) ((int) $request->status)]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => ['required', 'integer', Rule::exists('order_statuses', 'id')],
        ]);

        OrderStatus::whereIn('id', $request->ids)->delete();

        return response()->json(['success' => true]);
    }
}
