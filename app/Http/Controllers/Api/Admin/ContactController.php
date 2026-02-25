<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $query = Contact::orderByDesc('id');

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('hotline', 'LIKE', "%{$keyword}%")
                    ->orWhere('phone', 'LIKE', "%{$keyword}%")
                    ->orWhere('email', 'LIKE', "%{$keyword}%")
                    ->orWhere('address', 'LIKE', "%{$keyword}%");
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
            'hotline' => 'nullable|string|max:50',
            'bekash' => 'nullable|string|max:50',
            'nogod' => 'nullable|string|max:50',
            'hotmail' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'maplink' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
        ]);

        $data = [
            'hotline' => $this->normalizeOptionalText($validated['hotline'] ?? null),
            'bekash' => $this->normalizeOptionalText($validated['bekash'] ?? null),
            'nogod' => $this->normalizeOptionalText($validated['nogod'] ?? null),
            'hotmail' => $this->normalizeOptionalText($validated['hotmail'] ?? null),
            'phone' => $this->normalizeOptionalText($validated['phone'] ?? null),
            'email' => $this->normalizeOptionalText($validated['email'] ?? null),
            'address' => $this->normalizeOptionalText($validated['address'] ?? null),
            'maplink' => $this->normalizeOptionalText($validated['maplink'] ?? null),
            'status' => array_key_exists('status', $validated) ? (int) $validated['status'] : 1,
        ];

        $contact = Contact::query()->orderByDesc('id')->first();
        if ($contact) {
            $contact->update($data);
        } else {
            $contact = Contact::create($data);
        }

        return response()->json([
            'success' => true,
            'data' => $contact,
        ], $contact->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'hotline' => 'nullable|string|max:50',
            'bekash' => 'nullable|string|max:50',
            'nogod' => 'nullable|string|max:50',
            'hotmail' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'maplink' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
        ]);

        $contact = Contact::findOrFail($id);

        $textFields = ['hotline', 'bekash', 'nogod', 'hotmail', 'phone', 'email', 'address', 'maplink'];
        foreach ($textFields as $field) {
            if ($request->has($field)) {
                $contact->{$field} = $this->normalizeOptionalText($validated[$field] ?? null);
            }
        }

        if ($request->has('status')) {
            $contact->status = array_key_exists('status', $validated) ? (int) $validated['status'] : 1;
        }

        $contact->save();

        return response()->json([
            'success' => true,
            'data' => $contact,
        ]);
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'status' => 'required|integer',
        ]);

        Contact::whereIn('id', $request->ids)->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        Contact::whereIn('id', $request->ids)->delete();

        return response()->json(['success' => true]);
    }

    private function normalizeOptionalText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        return $text;
    }
}
