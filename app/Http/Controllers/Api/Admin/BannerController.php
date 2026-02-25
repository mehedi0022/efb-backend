<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\BannerCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class BannerController extends Controller
{
    public function index(Request $request)
    {
        $query = Banner::with('category')->orderByDesc('id');

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->keyword);
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('title', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('link', 'LIKE', '%' . $keyword . '%');
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

    public function meta()
    {
        $categories = BannerCategory::select('id', 'name')->orderBy('name')->get();
        return response()->json(['success' => true, 'categories' => $categories]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:banner_categories,id',
            'title' => 'required|string|max:255',
            'link' => 'required|string|max:500',
            'status' => 'required|boolean',
            'image' => 'required|file|image|max:5120',
            'image_two' => 'nullable|file|image|max:5120',
        ]);

        $uploadPath = 'uploads/banner/';
        if (!File::exists($uploadPath)) {
            File::makeDirectory($uploadPath, 0755, true);
        }

        $file = $request->file('image');
        $name = time() . $file->getClientOriginalName();
        $file->move($uploadPath, $name);
        $fileUrl = $uploadPath . $name;

        $fileUrlOpt = '';
        $fileOpt = $request->file('image_two');
        if ($fileOpt) {
            $nameOpt = time() . $fileOpt->getClientOriginalName();
            $fileOpt->move($uploadPath, $nameOpt);
            $fileUrlOpt = $uploadPath . $nameOpt;
        }

        $data = $request->all();
        $data['title'] = trim((string) $request->input('title', ''));
        $data['link'] = trim((string) $request->input('link', ''));
        $data['status'] = $request->status ? 1 : 0;
        $data['image'] = $fileUrl;
        $data['image_two'] = $fileUrlOpt;

        $banner = Banner::create($data);

        return response()->json(['success' => true, 'data' => $banner], 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'category_id' => 'required|exists:banner_categories,id',
            'title' => 'required|string|max:255',
            'link' => 'required|string|max:500',
            'status' => 'required|boolean',
            'image' => 'nullable|file|image|max:5120',
            'image_two' => 'nullable|file|image|max:5120',
        ]);

        $banner = Banner::findOrFail($id);
        $data = $request->all();
        $data['title'] = trim((string) $request->input('title', ''));
        $data['link'] = trim((string) $request->input('link', ''));

        $image = $request->file('image');
        if ($image) {
            $uploadPath = 'uploads/banner/';
            if (!File::exists($uploadPath)) {
                File::makeDirectory($uploadPath, 0755, true);
            }
            $name = time() . $image->getClientOriginalName();
            $image->move($uploadPath, $name);
            $data['image'] = $uploadPath . $name;
            if ($banner->image) {
                File::delete($banner->image);
            }
        } else {
            $data['image'] = $banner->image;
        }

        $fileOpt = $request->file('image_two');
        if ($fileOpt) {
            $uploadPathOpt = 'uploads/banner/';
            if (!File::exists($uploadPathOpt)) {
                File::makeDirectory($uploadPathOpt, 0755, true);
            }
            $nameOpt = time() . $fileOpt->getClientOriginalName();
            $fileOpt->move($uploadPathOpt, $nameOpt);
            $data['image_two'] = $uploadPathOpt . $nameOpt;
            if ($banner->image_two) {
                File::delete($banner->image_two);
            }
        } else {
            $data['image_two'] = $banner->image_two;
        }

        $data['status'] = $request->status ? 1 : 0;

        $banner->update($data);

        return response()->json(['success' => true, 'data' => $banner]);
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'status' => 'required|integer',
        ]);

        Banner::whereIn('id', $request->ids)->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        $banners = Banner::whereIn('id', $request->ids)->get();
        foreach ($banners as $banner) {
            if ($banner->image) {
                File::delete($banner->image);
            }
            if ($banner->image_two) {
                File::delete($banner->image_two);
            }
            $banner->delete();
        }

        return response()->json(['success' => true]);
    }
}
