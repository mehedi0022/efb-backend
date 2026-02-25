<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpBlock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IpBlockController extends Controller
{
    public function index(Request $request)
    {
        $query = IpBlock::query()->orderByDesc('id');

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->keyword);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('ip_no', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('reason', 'LIKE', '%' . $keyword . '%');
            });
        }

        $perPage = max(1, min((int) $request->get('per_page', 20), 100));
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
            'ip_no' => ['required', 'string', 'max:45', 'ip', Rule::unique('ip_blocks', 'ip_no')],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $ipAddress = $this->normalizeIp((string) $validated['ip_no']);
        if ($ipAddress === '') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid IP address format.',
            ], 422);
        }

        $item = IpBlock::create([
            'ip_no' => $ipAddress,
            'reason' => trim((string) $validated['reason']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'IP address blocked successfully.',
            'data' => $item,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $item = IpBlock::findOrFail($id);

        $validated = $request->validate([
            'ip_no' => ['required', 'string', 'max:45', 'ip', Rule::unique('ip_blocks', 'ip_no')->ignore($item->id)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $ipAddress = $this->normalizeIp((string) $validated['ip_no']);
        if ($ipAddress === '') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid IP address format.',
            ], 422);
        }

        $item->update([
            'ip_no' => $ipAddress,
            'reason' => trim((string) $validated['reason']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Blocked IP updated successfully.',
            'data' => $item->fresh(),
        ]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'integer', Rule::exists('ip_blocks', 'id')],
        ]);

        IpBlock::whereIn('id', $request->ids)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Blocked IP deleted successfully.',
        ]);
    }

    private function normalizeIp(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        // Handle IPv4-mapped IPv6 values like ::ffff:127.0.0.1
        if (str_starts_with($normalized, '::ffff:')) {
            $normalized = substr($normalized, 7);
        }

        $packed = @inet_pton($normalized);
        if ($packed === false) {
            return '';
        }

        $result = inet_ntop($packed);

        return is_string($result) ? strtolower($result) : '';
    }
}
