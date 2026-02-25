<?php

namespace App\Http\Middleware;

use App\Models\IpBlock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockIpAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawIp = trim((string) $request->ip());
        $normalizedIp = $this->normalizeIp($rawIp);

        if ($normalizedIp !== '') {
            $blockedIp = IpBlock::query()
                ->where('ip_no', $normalizedIp)
                ->when($rawIp !== '' && $rawIp !== $normalizedIp, function ($query) use ($rawIp) {
                    $query->orWhere('ip_no', $rawIp);
                })
                ->first();

            if ($blockedIp) {
                $reason = trim((string) $blockedIp->reason);

                return response()->json([
                    'success' => false,
                    'blocked' => true,
                    'code' => 'ip_blocked',
                    'reason' => $reason,
                    'message' => 'You are restricted from accessing this service.' . ($reason !== '' ? ' ' . $reason : ''),
                    'ip' => $normalizedIp,
                ], 403);
            }
        }

        return $next($request);
    }

    private function normalizeIp(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

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
