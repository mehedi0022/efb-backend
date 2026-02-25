<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Size;

class SizeController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Size::query();

            if ($request->filled('keyword')) {
                $query->where('sizeName', 'LIKE', '%' . $request->keyword . '%');
            }

            if ($request->filled('status')) {
                $query->where('status', (string)$request->status);
            }

            $perPage = $request->get('per_page', 20);
            $sizes = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $sizes->map(function($size) {
                    return [
                        'id' => $size->id,
                        'name' => $size->sizeName,
                        'status' => $size->status,
                        'status_text' => $size->status == '1' ? 'Active' : 'Inactive',
                    ];
                }),
                'pagination' => [
                    'total' => $sizes->total(),
                    'current_page' => $sizes->currentPage(),
                    'last_page' => $sizes->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255|unique:sizes,sizeName']);

        try {
            $size = new Size();
            $size->sizeName = $request->name;
            $size->status = (string)($request->status ?? 1);
            $size->save();

            return response()->json([
                'success' => true,
                'message' => 'Size created successfully',
                'data' => $size,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate(['name' => 'required|string|max:255|unique:sizes,sizeName,' . $id]);

        try {
            $size = Size::findOrFail($id);
            $size->sizeName = $request->name;
            if ($request->filled('status')) {
                $size->status = (string)$request->status;
            }
            $size->save();

            return response()->json([
                'success' => true,
                'message' => 'Size updated successfully',
                'data' => $size,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request)
    {
        $request->validate(['size_ids' => 'required|array']);

        try {
            Size::whereIn('id', $request->size_ids)->delete();
            return response()->json(['success' => true, 'message' => 'Deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'size_ids' => 'required|array',
            'status' => 'required|in:0,1',
        ]);

        try {
            Size::whereIn('id', $request->size_ids)->update(['status' => (string)$request->status]);
            return response()->json(['success' => true, 'message' => 'Status updated']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
