<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class ExternalProxyController extends Controller
{
    private function baseUrl(): string
    {
        $base = trim((string) config('services.api.base_url'));
        if ($base === '') {
            $base = trim((string) env('API_BASE_URL'));
        }
        return rtrim($base !== '' ? $base : 'https://api.freelancerbangladesh.com', '/');
    }

    private function extractHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!$host) {
            $host = preg_replace('#^https?://#i', '', $value);
            $host = preg_replace('#/.*$#', '', (string) $host);
        }

        return strtolower((string) $host);
    }

    private function normalizeDomain(string $appUrl): string
    {
        $host = $this->extractHost($appUrl);
        if ($host === '') {
            return 'www.freelancerbangladesh.com';
        }

        $host = preg_replace('#^www\.#i', '', $host);

        return 'www.' . $host;
    }

    private function domainCandidates(?Request $request = null): array
    {
        $hosts = [];

        $configuredHost = $this->extractHost((string) config('app.url', ''));
        if ($configuredHost !== '') {
            $hosts[] = $configuredHost;
        }

        if ($request !== null) {
            $requestHost = $this->extractHost((string) $request->getHost());
            if ($requestHost !== '') {
                $hosts[] = $requestHost;
            }
        }

        if (empty($hosts)) {
            return ['www.freelancerbangladesh.com', 'freelancerbangladesh.com'];
        }

        $domains = [];
        foreach (array_unique($hosts) as $host) {
            $base = preg_replace('#^www\.#i', '', $host);
            if ($base === '') {
                continue;
            }
            $domains[] = 'www.' . $base;
            $domains[] = $base;
        }

        return array_values(array_unique(array_filter($domains)));
    }

    private function normalizeProductSlug(string $slug): string
    {
        $value = trim($slug);
        if ($value === '') {
            return '';
        }

        $pathFromUrl = parse_url($value, PHP_URL_PATH);
        if (is_string($pathFromUrl) && $pathFromUrl !== '') {
            $value = $pathFromUrl;
        }

        $value = trim($value);
        $value = explode('?', $value)[0];
        $value = explode('#', $value)[0];
        $value = trim($value, '/');

        if ($value === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $value), static fn ($segment) => trim($segment) !== ''));
        if (empty($segments)) {
            return '';
        }

        return trim((string) end($segments));
    }

    private function productSlugCandidates(string $slug): array
    {
        $raw = trim($slug);
        if ($raw === '') {
            return [];
        }

        $decoded = rawurldecode($raw);
        $normalizedRaw = $this->normalizeProductSlug($raw);
        $normalizedDecoded = $this->normalizeProductSlug($decoded);

        $candidates = [
            $normalizedRaw,
            $normalizedDecoded,
        ];

        if ($normalizedRaw !== '') {
            $candidates[] = rawurlencode($normalizedRaw);
        }

        if ($normalizedDecoded !== '') {
            $candidates[] = rawurlencode($normalizedDecoded);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function request(string|array $endpoints, array $params = [])
    {
        $endpointList = is_array($endpoints)
            ? array_values(array_unique(array_filter(array_map(static fn ($endpoint) => trim((string) $endpoint), $endpoints))))
            : [trim($endpoints)];

        $lastResponseStatus = null;

        foreach ($endpointList as $endpoint) {
            if ($endpoint === '') {
                continue;
            }

            try {
                $response = Http::timeout(15)->connectTimeout(5)->get($endpoint, $params);
            } catch (Throwable) {
                continue;
            }

            if ($response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $lastResponseStatus = $response->status();

            // Fallback attempts are only useful for not-found variants.
            if ($response->status() !== 404) {
                return response()->json([
                    'success' => false,
                    'message' => 'External API request failed.',
                    'status' => $response->status(),
                ], $response->status() ?: 502);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'External API request failed.',
            'status' => $lastResponseStatus ?? 404,
        ], $lastResponseStatus ?: 502);
    }

    public function featuredCategories(Request $request)
    {
        $domain = $this->normalizeDomain(config('app.url', ''));
        $endpoint = $this->baseUrl() . "/categories/feature/{$domain}";

        $params = [
            'page' => $request->input('page', 1),
            'limit' => $request->input('limit', 20),
        ];

        return $this->request($endpoint, $params);
    }

    public function menuCategories(Request $request)
    {
        $domain = $this->normalizeDomain(config('app.url', ''));
        $endpoint = $this->baseUrl() . "/categories/menu/{$domain}";

        $params = [
            'page' => $request->input('page', 1),
            'limit' => $request->input('limit', 40),
        ];

        return $this->request($endpoint, $params);
    }

    public function topSell(Request $request)
    {
        $domain = $this->normalizeDomain(config('app.url', ''));
        $endpoint = $this->baseUrl() . "/products/top-sell/{$domain}";

        $params = [
            'page' => $request->input('page', 1),
            'limit' => $request->input('limit', 12),
        ];

        return $this->request($endpoint, $params);
    }

    public function hotDeals(Request $request)
    {
        $domain = $this->normalizeDomain(config('app.url', ''));
        $endpoint = $this->baseUrl() . "/products/hot-deal/{$domain}";

        $params = [
            'page' => $request->input('page', 1),
            'limit' => $request->input('limit', 12),
        ];

        return $this->request($endpoint, $params);
    }

    public function categoryProducts(Request $request)
    {
        $domain = $this->normalizeDomain(config('app.url', ''));
        $endpoint = $this->baseUrl() . "/category-products/{$domain}";

        $params = [
            'cat_page' => $request->input('cat_page', 1),
            'cat_limit' => $request->input('cat_limit', 10),
            'prod_page' => $request->input('prod_page', 1),
            'prod_limit' => $request->input('prod_limit', 12),
        ];

        return $this->request($endpoint, $params);
    }

    public function productDetails(Request $request, string $slug)
    {
        $slugCandidates = $this->productSlugCandidates($slug);
        if (empty($slugCandidates)) {
            return response()->json([
                'success' => false,
                'message' => 'External API request failed.',
                'status' => 404,
            ], 404);
        }

        $domains = $this->domainCandidates($request);
        $endpoints = [];
        foreach ($domains as $domain) {
            foreach ($slugCandidates as $candidateSlug) {
                $endpoints[] = $this->baseUrl() . "/products/slug/{$domain}/{$candidateSlug}";
            }
        }

        return $this->request($endpoints);
    }

    public function categoryProductsBySlug(Request $request, string $slug)
    {
        $domain = $this->normalizeDomain(config('app.url', ''));
        $endpoint = $this->baseUrl() . "/products/category/{$domain}/{$slug}";

        $params = [
            'page' => $request->input('page', 1),
            'limit' => $request->input('limit', 20),
        ];

        return $this->request($endpoint, $params);
    }

    public function userSubcategoryProducts(Request $request, string $slug)
{
    $domain = $this->normalizeDomain(config('app.url', ''));
    
    $endpoint = $this->baseUrl() . "/products/get-user-subcategory-products/{$domain}/{$slug}";

    $params = [
        'page' => $request->input('page', 1),
        'limit' => $request->input('limit', 20),
    ];

    return $this->request($endpoint, $params);
}

    public function searchProducts(Request $request)
    {
        $domain = $this->normalizeDomain(config('app.url', ''));
        $endpoint = $this->baseUrl() . "/products/get-product/{$domain}";

        $params = [
            'search' => $request->input('keyword', $request->input('search', '')),
            'page' => $request->input('page', 1),
            'limit' => $request->input('limit', 20),
        ];

        return $this->request($endpoint, $params);
    }
}
