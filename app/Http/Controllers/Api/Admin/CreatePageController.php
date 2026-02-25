<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreatePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CreatePageController extends Controller
{
    public function index(Request $request)
    {
        $query = CreatePage::orderByDesc('id');

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'LIKE', "%{$keyword}%")
                    ->orWhere('title', 'LIKE', "%{$keyword}%");
            });
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
            'title' => 'required',
            'description' => 'required',
            'status' => 'required',
            'footer_section' => 'nullable|in:useful,reference,none',
            'footer_sort_order' => 'nullable|integer|min:0',
        ]);

        $data = $request->all();
        $data['slug'] = strtolower(preg_replace('/\s+/', '-', $request->name));
        $data = $this->applyFooterConfiguration($data, $request);

        $page = CreatePage::create($data);

        return response()->json([
            'success' => true,
            'data' => $page,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required',
            'title' => 'required',
            'description' => 'required',
            'footer_section' => 'nullable|in:useful,reference,none',
            'footer_sort_order' => 'nullable|integer|min:0',
        ]);

        $page = CreatePage::findOrFail($id);
        $data = $request->all();
        $data['slug'] = strtolower(preg_replace('/\s+/', '-', $request->name));
        $data = $this->applyFooterConfiguration($data, $request);
        $page->update($data);

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'status' => 'required|integer',
        ]);

        CreatePage::whereIn('id', $request->ids)->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        CreatePage::whereIn('id', $request->ids)->delete();

        return response()->json(['success' => true]);
    }

    private function applyFooterConfiguration(array $data, Request $request): array
    {
        $columns = $this->tableColumns();

        if (isset($columns['footer_section'])) {
            $section = strtolower((string) $request->input('footer_section', $data['footer_section'] ?? 'useful'));
            if (!in_array($section, ['useful', 'reference', 'none'], true)) {
                $section = 'useful';
            }
            $data['footer_section'] = $section;
        }

        if (isset($columns['footer_sort_order'])) {
            $data['footer_sort_order'] = max(0, (int) $request->input('footer_sort_order', $data['footer_sort_order'] ?? 0));
        }

        return $data;
    }

    private function tableColumns(): array
    {
        $table = (new CreatePage())->getTable();
        return array_flip(Schema::getColumnListing($table));
    }
}
