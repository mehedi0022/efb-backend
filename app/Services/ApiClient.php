<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ApiClient
{
    public function request(): PendingRequest
    {
        $timeout = (int) config('services.api.timeout', 20);
        $connectTimeout = (int) config('services.api.connect_timeout', 5);
        $retries = max(0, (int) config('services.api.retry', 2));
        $retryDelay = max(0, (int) config('services.api.retry_delay', 500));

        $request = Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->retry($retries, $retryDelay)
            ->acceptJson();

        if (config('services.api.force_ipv4')) {
            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                $request = $request->withOptions([
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                ]);
            }
        }

        return $request;
    }
}
