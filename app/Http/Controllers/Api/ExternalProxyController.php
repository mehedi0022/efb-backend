<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

    private function normalizeDomain(string $appUrl): string
    {
        $host = parse_url($appUrl, PHP_URL_HOST);
        if (!$host) {
            $host = preg_replace('#^https?://#i', '', $appUrl);
            $host = preg_replace('#/.*$#', '', $host);
        }

        $host = strtolower($host);
        $host = preg_replace('#^www\.#i', '', $host);

        return 'www.' . $host;
    }

    private function request(string $endpoint, array $params = [])
    {
        $response = Http::timeout(15)->connectTimeout(5)->get($endpoint, $params);

        if ($response->successful()) {
            return response()->json($response->json(), $response->status());
        }

        return response()->json([
            'success' => false,
            'message' => 'External API request failed.',
            'status' => $response->status(),
        ], $response->status() ?: 502);
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

    public function productDetails(string $slug)
    {
        $domain = $this->normalizeDomain(config('app.url', ''));
        $endpoint = $this->baseUrl() . "/products/slug/{$domain}/{$slug}";

        return $this->request($endpoint);
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
