<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SocialMedia;
use Illuminate\Http\Request;

class FooterSocialLinkController extends Controller
{
    public function index(Request $request)
    {
        $query = SocialMedia::query()->orderBy('sort_order')->orderBy('id');

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->keyword);
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('icon', 'LIKE', "%{$keyword}%")
                    ->orWhere('url', 'LIKE', "%{$keyword}%");
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
        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'icon' => 'required|string|max:60',
            'url' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'required|integer|in:0,1',
        ]);

        $item = SocialMedia::create($this->preparePayload($validated));

        return response()->json([
            'success' => true,
            'data' => $item,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'icon' => 'required|string|max:60',
            'url' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'required|integer|in:0,1',
        ]);

        $item = SocialMedia::findOrFail($id);
        $item->update($this->preparePayload($validated));

        return response()->json([
            'success' => true,
            'data' => $item,
        ]);
    }

    public function updateStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'status' => 'required|integer|in:0,1',
        ]);

        SocialMedia::whereIn('id', $validated['ids'])->update([
            'status' => (int) $validated['status'],
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
        ]);

        SocialMedia::whereIn('id', $validated['ids'])->delete();

        return response()->json(['success' => true]);
    }

    private function preparePayload(array $validated): array
    {
        return [
            'title' => trim((string) $validated['title']),
            'icon' => trim((string) $validated['icon']),
            'url' => trim((string) $validated['url']),
            'sort_order' => max(0, (int) ($validated['sort_order'] ?? 0)),
            'status' => (int) $validated['status'],
        ];
    }
}
