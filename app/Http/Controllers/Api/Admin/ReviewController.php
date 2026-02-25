<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $query = Review::with([
            'product:id,name',
            'customer:id,name,email,phone',
        ])->orderByDesc('id');

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'LIKE', "%{$keyword}%")
                    ->orWhere('email', 'LIKE', "%{$keyword}%")
                    ->orWhere('review', 'LIKE', "%{$keyword}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = (int) $request->get('per_page', 20);
        $reviews = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $reviews->items(),
            'pagination' => [
                'total' => $reviews->total(),
                'per_page' => $reviews->perPage(),
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'from' => $reviews->firstItem(),
                'to' => $reviews->lastItem(),
            ],
        ]);
    }

    public function pending(Request $request)
    {
        $request->merge(['status' => 'pending']);
        return $this->index($request);
    }

    public function meta()
    {
        $products = Product::where('status', 1)->select('id', 'name')->orderBy('name')->get();
        $customers = Customer::where('status', 'active')->select('id', 'name', 'email', 'phone')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'products' => $products,
            'customers' => $customers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'ratting' => 'required',
            'review' => 'required',
            'product_id' => 'required|exists:products,id',
            'status' => 'required',
        ]);

        $customer = Customer::find($validated['customer_id']);

        $data = $request->all();
        $data['name'] = $customer->name ?? 'N / A';
        $data['email'] = $customer->email ?? 'N / A';
        $data['status'] = (string) ($request->status == 1 ? 'active' : 'pending');

        $review = Review::create($data);

        return response()->json([
            'success' => true,
            'data' => $review,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required',
            'email' => 'required',
            'ratting' => 'required',
            'review' => 'required',
            'product_id' => 'required|exists:products,id',
        ]);

        $review = Review::findOrFail($id);
        $data = $request->all();
        $data['status'] = $request->status ? 'active' : 'pending';

        $review->update($data);

        return response()->json([
            'success' => true,
            'data' => $review,
        ]);
    }

    public function activate($id)
    {
        $review = Review::findOrFail($id);
        $review->status = 'active';
        $review->save();

        return response()->json(['success' => true]);
    }

    public function deactivate($id)
    {
        $review = Review::findOrFail($id);
        $review->status = 'pending';
        $review->save();

        return response()->json(['success' => true]);
    }

    public function destroy($id)
    {
        $review = Review::findOrFail($id);
        $review->delete();

        return response()->json(['success' => true]);
    }
}
