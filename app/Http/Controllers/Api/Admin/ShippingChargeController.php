<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingCharge;
use Illuminate\Http\Request;

class ShippingChargeController extends Controller
{
    public function index(Request $request)
    {
        $query = ShippingCharge::orderBy('id', 'ASC');

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where('name', 'LIKE', "%{$keyword}%");
        }

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

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'status' => 'required',
        ]);

        $data = $request->all();
        $data['status'] = $request->status ? 1 : 0;
        $data['front_view'] = $request->front_view ? 1 : 0;

        $item = ShippingCharge::create($data);

        return response()->json([
            'success' => true,
            'data' => $item,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required',
        ]);

        $item = ShippingCharge::findOrFail($id);
        $data = $request->all();
        $data['status'] = $request->status ? 1 : 0;
        $data['front_view'] = $request->front_view ? 1 : 0;
        $item->update($data);

        return response()->json([
            'success' => true,
            'data' => $item,
        ]);
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'status' => 'required|integer',
        ]);

        ShippingCharge::whereIn('id', $request->ids)->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        ShippingCharge::whereIn('id', $request->ids)->delete();

        return response()->json(['success' => true]);
    }
}
