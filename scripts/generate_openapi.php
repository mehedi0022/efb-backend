<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);
$appUrl = getenv('APP_URL');
if (!is_string($appUrl) || trim($appUrl) === '') {
    $envPath = $projectRoot . '/.env';
    if (is_file($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            if (trim($key) !== 'APP_URL') {
                continue;
            }

            $value = trim($value);
            $value = trim($value, "\"'");
            if ($value !== '') {
                $appUrl = $value;
            }
            break;
        }
    }
}

if (!is_string($appUrl) || trim($appUrl) === '') {
    $appUrl = 'http://127.0.0.1:8000';
}

$localServerUrl = 'http://127.0.0.1:8000';
$servers = [
    [
        'url' => $localServerUrl,
        'description' => 'Local development',
    ],
];

if (rtrim($appUrl, '/') !== rtrim($localServerUrl, '/')) {
    $servers[] = [
        'url' => $appUrl,
        'description' => 'Configured APP_URL',
    ];
}

$routeListJson = shell_exec('php artisan route:list --path=api --json 2>/dev/null');
if (!is_string($routeListJson) || trim($routeListJson) === '') {
    fwrite(STDERR, "Failed to load route list.\n");
    exit(1);
}

$routes = json_decode($routeListJson, true);
if (!is_array($routes)) {
    fwrite(STDERR, "Invalid route list JSON.\n");
    exit(1);
}

$spec = [
    'openapi' => '3.0.3',
    'info' => [
        'title' => 'Laravel Ecommerce API',
        'version' => '1.0.0',
        'description' => 'Auto-generated OpenAPI spec from Laravel route definitions.',
    ],
    'servers' => $servers,
    'tags' => [
        ['name' => 'Public Auth'],
        ['name' => 'Public Products'],
        ['name' => 'Public Content'],
        ['name' => 'Public Cart'],
        ['name' => 'Public Checkout'],
        ['name' => 'Public External'],
        ['name' => 'Admin Auth'],
        ['name' => 'Admin Dashboard'],
        ['name' => 'Admin Orders'],
        ['name' => 'Admin Catalog'],
        ['name' => 'Admin Reviews'],
        ['name' => 'Admin Settings'],
        ['name' => 'Admin Integrations'],
        ['name' => 'Admin Marketing'],
        ['name' => 'Admin Reports'],
        ['name' => 'Admin Incomplete Orders'],
        ['name' => 'Admin Users'],
    ],
    'components' => [
        'securitySchemes' => [
            'adminBearer' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'Admin JWT access token',
            ],
            'customerBearer' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'Customer JWT access token',
            ],
        ],
    ],
    'paths' => [],
];

$seenOperationIds = [];

foreach ($routes as $route) {
    $uri = '/' . ltrim((string) ($route['uri'] ?? ''), '/');
    if (!str_starts_with($uri, '/api/')) {
        continue;
    }

    $rawMethods = explode('|', (string) ($route['method'] ?? 'GET'));
    $middlewares = array_map('strval', $route['middleware'] ?? []);
    $authType = detectAuthType($middlewares);
    $tag = inferTagFromUri($uri);

    foreach ($rawMethods as $rawMethod) {
        $method = strtoupper(trim($rawMethod));
        if ($method === '' || $method === 'HEAD') {
            continue;
        }

        $httpMethod = strtolower($method);
        $operationId = buildOperationId($method, $uri);
        $operationId = ensureUniqueOperationId($operationId, $seenOperationIds);

        $operation = [
            'tags' => [$tag],
            'summary' => sprintf('%s %s', $method, $uri),
            'operationId' => $operationId,
            'responses' => buildResponses($method, $authType),
        ];

        $parameters = extractPathParameters($uri);
        if (!empty($parameters)) {
            $operation['parameters'] = $parameters;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $operation['requestBody'] = [
                'required' => in_array($method, ['POST', 'PUT', 'PATCH'], true),
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ];
        }

        if ($authType === 'admin') {
            $operation['security'] = [['adminBearer' => []]];
        } elseif ($authType === 'customer') {
            $operation['security'] = [['customerBearer' => []]];
        } elseif ($authType === 'customer_optional') {
            $operation['security'] = [
                ['customerBearer' => []],
                (object) [],
            ];
        } else {
            $operation['security'] = [];
        }

        $spec['paths'][$uri][$httpMethod] = $operation;
    }
}

ksort($spec['paths']);

$outputPath = $projectRoot . '/public/openapi.json';
$encoded = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    fwrite(STDERR, "Failed to encode OpenAPI JSON.\n");
    exit(1);
}

file_put_contents($outputPath, $encoded . PHP_EOL);
echo "Generated {$outputPath}\n";

function detectAuthType(array $middlewares): string
{
    $joined = implode(' ', $middlewares);

    if (str_contains($joined, 'JwtAuthenticate:admin')) {
        return 'admin';
    }

    if (str_contains($joined, 'JwtOptionalAuthenticate:customer')) {
        return 'customer_optional';
    }

    if (str_contains($joined, 'JwtAuthenticate:customer')) {
        return 'customer';
    }

    return 'public';
}

function inferTagFromUri(string $uri): string
{
    if (str_starts_with($uri, '/api/admin/login') || str_starts_with($uri, '/api/admin/refresh-token') || str_starts_with($uri, '/api/admin/logout') || str_starts_with($uri, '/api/admin/me')) {
        return 'Admin Auth';
    }

    if (str_starts_with($uri, '/api/admin/dashboard')) {
        return 'Admin Dashboard';
    }

    if (str_starts_with($uri, '/api/admin/orders')) {
        return 'Admin Orders';
    }

    if (str_starts_with($uri, '/api/admin/incomplete-orders')) {
        return 'Admin Incomplete Orders';
    }

    if (str_starts_with($uri, '/api/admin/reports')) {
        return 'Admin Reports';
    }

    if (str_starts_with($uri, '/api/admin/users')) {
        return 'Admin Users';
    }

    if (
        str_starts_with($uri, '/api/admin/products') ||
        str_starts_with($uri, '/api/admin/categories') ||
        str_starts_with($uri, '/api/admin/subcategories') ||
        str_starts_with($uri, '/api/admin/brands') ||
        str_starts_with($uri, '/api/admin/colors') ||
        str_starts_with($uri, '/api/admin/sizes') ||
        str_starts_with($uri, '/api/admin/shipping-charges') ||
        str_starts_with($uri, '/api/admin/order-statuses')
    ) {
        return 'Admin Catalog';
    }

    if (str_starts_with($uri, '/api/admin/reviews')) {
        return 'Admin Reviews';
    }

    if (
        str_starts_with($uri, '/api/admin/settings') ||
        str_starts_with($uri, '/api/admin/contacts') ||
        str_starts_with($uri, '/api/admin/pages')
    ) {
        return 'Admin Settings';
    }

    if (str_starts_with($uri, '/api/admin/integrations')) {
        return 'Admin Integrations';
    }

    if (
        str_starts_with($uri, '/api/admin/pixels') ||
        str_starts_with($uri, '/api/admin/tag-managers') ||
        str_starts_with($uri, '/api/admin/banner-categories') ||
        str_starts_with($uri, '/api/admin/banners')
    ) {
        return 'Admin Marketing';
    }

    if (
        str_starts_with($uri, '/api/v1/login') ||
        str_starts_with($uri, '/api/v1/register') ||
        str_starts_with($uri, '/api/v1/refresh-token') ||
        str_starts_with($uri, '/api/v1/logout') ||
        str_starts_with($uri, '/api/v1/user')
    ) {
        return 'Public Auth';
    }

    if (str_starts_with($uri, '/api/v1/cart')) {
        return 'Public Cart';
    }

    if (str_starts_with($uri, '/api/v1/checkout')) {
        return 'Public Checkout';
    }

    if (str_starts_with($uri, '/api/v1/external')) {
        return 'Public External';
    }

    if (
        str_starts_with($uri, '/api/v1/products') ||
        str_starts_with($uri, '/api/v1/home-data') ||
        str_starts_with($uri, '/api/v1/home-categories')
    ) {
        return 'Public Products';
    }

    return 'Public Content';
}

function buildOperationId(string $method, string $uri): string
{
    $base = strtolower($method . '_' . trim($uri, '/'));
    $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: 'operation';
    return trim($base, '_');
}

function ensureUniqueOperationId(string $operationId, array &$seen): string
{
    if (!isset($seen[$operationId])) {
        $seen[$operationId] = 1;
        return $operationId;
    }

    $seen[$operationId]++;
    return $operationId . '_' . $seen[$operationId];
}

function extractPathParameters(string $uri): array
{
    preg_match_all('/\{([^}]+)\}/', $uri, $matches);
    $parameters = [];

    foreach ($matches[1] as $name) {
        $parameters[] = [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
            'description' => sprintf('Path parameter: %s', $name),
        ];
    }

    return $parameters;
}

function buildResponses(string $method, string $authType): array
{
    $successCode = $method === 'POST' ? '201' : '200';
    $responses = [
        $successCode => ['description' => 'Successful response'],
        '422' => ['description' => 'Validation error'],
        '500' => ['description' => 'Internal server error'],
    ];

    if (in_array($authType, ['admin', 'customer', 'customer_optional'], true)) {
        $responses['401'] = ['description' => 'Unauthorized'];
    }

    return $responses;
}
