<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    public function index(Request $request)
    {
        $limit = max(1, min((int) $request->input('limit', 10), 50));

        $query = Banner::query()
            ->where('status', 1)
            ->orderByDesc('id');

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        $banners = $query
            ->limit($limit)
            ->get([
                'id',
                'title',
                'category_id',
                'link',
                'image',
                'status',
                'created_at',
                'updated_at',
            ]);

        return response()->json([
            'success' => true,
            'data' => $banners,
        ]);
    }
}
