<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Brand;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Brand::query();

            if ($request->filled('keyword')) {
                $query->where('name', 'LIKE', '%' . $request->keyword . '%');
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $perPage = $request->get('per_page', 20);
            $brands = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $brands->map(function($brand) {
                    return [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'name_bn' => $brand->name_bn ?? $brand->name,
                        'slug' => $brand->slug ?? Str::slug($brand->name),
                        'status' => $brand->status,
                        'status_text' => $brand->status ? 'Active' : 'Inactive',
                    ];
                }),
                'pagination' => [
                    'total' => $brands->total(),
                    'current_page' => $brands->currentPage(),
                    'last_page' => $brands->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
            'name_bn' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
        ]);

        try {
            $brand = new Brand();
            $brand->name = $request->name;
            $brand->name_bn = $this->resolveBanglaName($request->name, $request->input('name_bn'));
            $brand->slug = Str::slug($request->name);
            $brand->status = $request->status ?? 1;
            $brand->save();

            return response()->json([
                'success' => true,
                'message' => 'Brand created successfully',
                'data' => $brand,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:brands,name,' . $id,
            'name_bn' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
        ]);

        try {
            $brand = Brand::findOrFail($id);
            $brand->name = $request->name;
            $brand->name_bn = $this->resolveBanglaName($request->name, $request->input('name_bn'));
            $brand->slug = Str::slug($request->name);
            if ($request->filled('status')) {
                $brand->status = $request->status;
            }
            $brand->save();

            return response()->json([
                'success' => true,
                'message' => 'Brand updated successfully',
                'data' => $brand,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request)
    {
        $request->validate(['brand_ids' => 'required|array']);

        try {
            Brand::whereIn('id', $request->brand_ids)->delete();
            return response()->json(['success' => true, 'message' => 'Deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'brand_ids' => 'required|array',
            'status' => 'required|integer|in:0,1',
        ]);

        try {
            Brand::whereIn('id', $request->brand_ids)->update(['status' => $request->status]);
            return response()->json(['success' => true, 'message' => 'Status updated']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function resolveBanglaName(string $name, mixed $nameBn): string
    {
        $normalized = trim((string) ($nameBn ?? ''));
        if ($normalized !== '') {
            return $normalized;
        }

        return trim($name);
    }
}
