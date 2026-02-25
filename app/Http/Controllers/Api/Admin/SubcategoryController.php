<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SubcategoryController extends Controller
{
    private function resolveNameColumn(): string
    {
        static $nameColumn = null;

        if ($nameColumn !== null) {
            return $nameColumn;
        }

        $nameColumn = Schema::hasColumn('subcategories', 'name') ? 'name' : 'subcategoryName';
        return $nameColumn;
    }

    private function resolveSubcategoryName(Request $request): string
    {
        $name = $request->input('name', $request->input('subcategoryName', ''));
        return trim((string) $name);
    }

    private function resolveIds(Request $request): array
    {
        $ids = $request->input('subcategory_ids', $request->input('ids', []));
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, static fn ($id) => is_numeric($id)));
    }

    public function index(Request $request)
    {
        try {
            $nameColumn = $this->resolveNameColumn();
            $query = Subcategory::with('category');

            if ($request->filled('keyword')) {
                $query->where($nameColumn, 'LIKE', '%' . trim((string) $request->keyword) . '%');
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $perPage = $request->get('per_page', 20);
            $subcategories = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $subcategories->map(function($sub) {
                    return [
                        'id' => $sub->id,
                        'name' => $sub->subcategoryName ?? $sub->name,
                        'slug' => $sub->slug,
                        'category' => $sub->category ? [
                            'id' => $sub->category->id,
                            'name' => $sub->category->name,
                        ] : null,
                        'status' => $sub->status,
                        'status_text' => $sub->status ? 'Active' : 'Inactive',
                    ];
                }),
                'pagination' => [
                    'total' => $subcategories->total(),
                    'current_page' => $subcategories->currentPage(),
                    'last_page' => $subcategories->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'nullable|required_without:subcategoryName|string|max:255',
            'subcategoryName' => 'nullable|required_without:name|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'status' => 'nullable|integer|in:0,1',
        ]);

        try {
            $nameColumn = $this->resolveNameColumn();
            $name = $this->resolveSubcategoryName($request);

            $subcategory = new Subcategory();
            $subcategory->{$nameColumn} = $name;
            $subcategory->slug = Str::slug($name);
            $subcategory->category_id = $request->category_id;
            $subcategory->status = (int) ($request->status ?? 1);
            $subcategory->save();

            return response()->json([
                'success' => true,
                'message' => 'Subcategory created successfully',
                'data' => $subcategory,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'nullable|required_without:subcategoryName|string|max:255',
            'subcategoryName' => 'nullable|required_without:name|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'status' => 'nullable|integer|in:0,1',
        ]);

        try {
            $nameColumn = $this->resolveNameColumn();
            $name = $this->resolveSubcategoryName($request);

            $subcategory = Subcategory::findOrFail($id);
            $subcategory->{$nameColumn} = $name;
            $subcategory->slug = Str::slug($name);
            $subcategory->category_id = $request->category_id;
            if ($request->filled('status')) {
                $subcategory->status = (int) $request->status;
            }
            $subcategory->save();

            return response()->json([
                'success' => true,
                'message' => 'Subcategory updated successfully',
                'data' => $subcategory,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'subcategory_ids' => 'nullable|array',
            'ids' => 'nullable|array',
        ]);

        try {
            $ids = $this->resolveIds($request);
            if (empty($ids)) {
                return response()->json(['success' => false, 'message' => 'No valid subcategory ids provided.'], 422);
            }

            Subcategory::whereIn('id', $ids)->delete();
            return response()->json(['success' => true, 'message' => 'Deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'subcategory_ids' => 'nullable|array',
            'ids' => 'nullable|array',
            'status' => 'required|integer|in:0,1',
        ]);

        try {
            $ids = $this->resolveIds($request);
            if (empty($ids)) {
                return response()->json(['success' => false, 'message' => 'No valid subcategory ids provided.'], 422);
            }

            Subcategory::whereIn('id', $ids)->update(['status' => (int) $request->status]);
            return response()->json(['success' => true, 'message' => 'Status updated']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
