<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Color;

class ColorController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Color::query();

            if ($request->filled('keyword')) {
                $query->where('colorName', 'LIKE', '%' . $request->keyword . '%');
            }

            if ($request->filled('status')) {
                $query->where('status', (string)$request->status);
            }

            $perPage = $request->get('per_page', 20);
            $colors = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $colors->map(function($color) {
                    return [
                        'id' => $color->id,
                        'name' => $color->colorName,
                        'color_code' => $color->color,
                        'status' => $color->status,
                        'status_text' => $color->status == '1' ? 'Active' : 'Inactive',
                    ];
                }),
                'pagination' => [
                    'total' => $colors->total(),
                    'current_page' => $colors->currentPage(),
                    'last_page' => $colors->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255|unique:colors,colorName']);

        try {
            $color = new Color();
            $color->colorName = $request->name;
            $color->color = $request->color_code ?? '#000000';
            $color->status = (string)($request->status ?? 1);
            $color->save();

            return response()->json([
                'success' => true,
                'message' => 'Color created successfully',
                'data' => $color,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate(['name' => 'required|string|max:255|unique:colors,colorName,' . $id]);

        try {
            $color = Color::findOrFail($id);
            $color->colorName = $request->name;
            if ($request->has('color_code')) {
                $color->color = $request->color_code;
            }
            if ($request->filled('status')) {
                $color->status = (string)$request->status;
            }
            $color->save();

            return response()->json([
                'success' => true,
                'message' => 'Color updated successfully',
                'data' => $color,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request)
    {
        $request->validate(['color_ids' => 'required|array']);

        try {
            Color::whereIn('id', $request->color_ids)->delete();
            return response()->json(['success' => true, 'message' => 'Deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'color_ids' => 'required|array',
            'status' => 'required|in:0,1',
        ]);

        try {
            Color::whereIn('id', $request->color_ids)->update(['status' => (string)$request->status]);
            return response()->json(['success' => true, 'message' => 'Status updated']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
