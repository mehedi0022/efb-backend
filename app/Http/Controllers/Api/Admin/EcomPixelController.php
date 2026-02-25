<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcomPixel;
use Illuminate\Http\Request;

class EcomPixelController extends Controller
{
    public function index(Request $request)
    {
        $query = EcomPixel::orderByDesc('id');

        if ($request->filled('keyword')) {
            $query->where('code', 'LIKE', '%' . $request->keyword . '%');
        }

        $perPage = (int) $request->get('per_page', 20);
        $pixels = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $pixels->items(),
            'pagination' => [
                'total' => $pixels->total(),
                'per_page' => $pixels->perPage(),
                'current_page' => $pixels->currentPage(),
                'last_page' => $pixels->lastPage(),
                'from' => $pixels->firstItem(),
                'to' => $pixels->lastItem(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'status' => 'required|integer|in:0,1',
        ]);

        $pixel = EcomPixel::query()->orderByDesc('id')->first();
        if ($pixel) {
            $pixel->update([
                'code' => $validated['code'],
                'status' => (int) $validated['status'],
            ]);
        } else {
            $pixel = EcomPixel::create([
                'code' => $validated['code'],
                'status' => (int) $validated['status'],
            ]);
        }

        return response()->json(['success' => true, 'data' => $pixel], $pixel->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'status' => 'required|integer|in:0,1',
        ]);

        $pixel = EcomPixel::findOrFail($id);
        $pixel->update([
            'code' => $validated['code'],
            'status' => (int) $validated['status'],
        ]);

        return response()->json(['success' => true, 'data' => $pixel]);
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'status' => 'required|integer',
        ]);

        EcomPixel::whereIn('id', $request->ids)->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        EcomPixel::whereIn('id', $request->ids)->delete();

        return response()->json(['success' => true]);
    }
}
