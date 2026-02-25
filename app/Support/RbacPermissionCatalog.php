<?php

namespace App\Support;

class RbacPermissionCatalog
{
    /**
     * Default module-level permission matrix.
     * These are used for bootstrapping and as the canonical RBAC vocabulary.
     *
     * @return array<string, array<int, string>>
     */
    public static function modules(): array
    {
        return [
            'dashboard' => ['view'],
            'orders' => ['view', 'create', 'edit', 'delete'],
            'fraud-checker' => ['view', 'create', 'edit', 'delete'],
            'products' => ['view', 'create', 'edit', 'delete'],
            'categories' => ['view', 'create', 'edit', 'delete'],
            'subcategories' => ['view', 'create', 'edit', 'delete'],
            'brands' => ['view', 'create', 'edit', 'delete'],
            'colors' => ['view', 'create', 'edit', 'delete'],
            'sizes' => ['view', 'create', 'edit', 'delete'],
            'reviews' => ['view', 'create', 'edit', 'delete'],
            'settings' => ['view', 'create', 'edit', 'delete'],
            'ip-blocking' => ['view', 'create', 'edit', 'delete'],
            'integrations' => ['view', 'create', 'edit', 'delete'],
            'pixels' => ['view', 'create', 'edit', 'delete'],
            'tag-managers' => ['view', 'create', 'edit', 'delete'],
            'banner-categories' => ['view', 'create', 'edit', 'delete'],
            'banners' => ['view', 'create', 'edit', 'delete'],
            'reports' => ['view', 'create', 'edit', 'delete'],
            'incomplete-orders' => ['view', 'create', 'edit', 'delete'],
            'users' => ['view', 'create', 'edit', 'delete'],
            'roles' => ['view', 'create', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allPermissionNames(): array
    {
        $permissions = [];

        foreach (self::modules() as $module => $actions) {
            foreach ($actions as $action) {
                $permissions[] = self::buildPermissionName($module, $action);
            }
        }

        sort($permissions);

        return $permissions;
    }

    public static function buildPermissionName(string $module, string $action): string
    {
        return self::normalizeSegment($module) . '.' . self::normalizeSegment($action);
    }

    /**
     * @return array{module:string, action:string}
     */
    public static function splitPermissionName(string $permissionName): array
    {
        $normalized = trim(strtolower($permissionName));
        $position = strrpos($normalized, '.');

        if ($position === false) {
            return [
                'module' => $normalized,
                'action' => 'custom',
            ];
        }

        return [
            'module' => substr($normalized, 0, $position),
            'action' => substr($normalized, $position + 1),
        ];
    }

    public static function normalizeSegment(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('_', '-', $normalized);
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9\-.]/', '', $normalized) ?? $normalized;

        return trim($normalized, '.-');
    }
}
