<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoogleTagManager;
use Illuminate\Http\Request;

class GoogleTagManagerController extends Controller
{
    public function index(Request $request)
    {
        $query = GoogleTagManager::orderByDesc('id');

        if ($request->filled('keyword')) {
            $query->where('code', 'LIKE', '%' . $request->keyword . '%');
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
        $validated = $request->validate([
            'code' => 'required',
            'status' => 'required',
        ]);

        $item = GoogleTagManager::create($validated);

        return response()->json(['success' => true, 'data' => $item], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'code' => 'required',
        ]);

        $item = GoogleTagManager::findOrFail($id);
        $item->update([
            'code' => $request->code,
            'status' => $request->status ? 1 : 0,
        ]);

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'status' => 'required|integer',
        ]);

        GoogleTagManager::whereIn('id', $request->ids)->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        GoogleTagManager::whereIn('id', $request->ids)->delete();

        return response()->json(['success' => true]);
    }
}
