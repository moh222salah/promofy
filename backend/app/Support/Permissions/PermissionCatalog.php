<?php

namespace App\Support\Permissions;

/**
 * Single source of truth for every permission in the platform.
 *
 * A permission key is "<module>.<action>". Authorization is always enforced on the backend
 * (Gate / policies); the front end only uses the same keys to decide what to render.
 */
final class PermissionCatalog
{
    public const SUPER_ADMIN = 'super-admin';

    public const CUSTOMER = 'customer';

    /**
     * module => actions. "*_assigned" actions limit a user to records assigned to them.
     *
     * @var array<string, list<string>>
     */
    public const MODULES = [
        'dashboard' => ['view'],
        'analytics' => ['view', 'export'],
        'leads' => ['view', 'view_assigned', 'create', 'edit', 'edit_assigned', 'delete', 'assign', 'export'],
        'customers' => ['view', 'create', 'edit', 'delete', 'export'],
        'tasks' => ['view', 'view_assigned', 'create', 'edit', 'edit_assigned', 'delete'],
        'services' => ['view', 'create', 'edit', 'delete', 'publish', 'export'],
        'products' => ['view', 'create', 'edit', 'delete', 'publish', 'export', 'import'],
        'product_categories' => ['view', 'create', 'edit', 'delete'],
        'pricing' => ['view', 'create', 'edit', 'delete'],
        'orders' => ['view', 'edit', 'assign', 'cancel', 'export'],
        'payments' => ['view', 'manage', 'refund', 'export'],
        'quotations' => ['view', 'view_assigned', 'create', 'edit', 'edit_assigned', 'send', 'approve', 'delete', 'export'],
        'projects' => ['view', 'view_assigned', 'create', 'edit', 'edit_assigned', 'delete', 'export'],
        'portfolio' => ['view', 'create', 'edit', 'delete', 'publish'],
        'case_studies' => ['view', 'create', 'edit', 'delete', 'publish'],
        'pages' => ['view', 'create', 'edit', 'delete', 'publish'],
        'homepage' => ['view', 'edit', 'publish'],
        'articles' => ['view', 'create', 'edit', 'delete', 'publish'],
        'media' => ['view', 'create', 'edit', 'delete'],
        'testimonials' => ['view', 'create', 'edit', 'delete', 'publish'],
        'team' => ['view', 'create', 'edit', 'delete'],
        'navigation' => ['view', 'edit'],
        'messages' => ['view', 'edit', 'delete', 'export'],
        'marketing' => ['view', 'manage'],
        'seo' => ['view', 'edit'],
        'users' => ['view', 'create', 'edit', 'delete', 'manage', 'export'],
        'roles' => ['view', 'create', 'edit', 'delete'],
        'notifications' => ['view', 'manage'],
        'audit' => ['view', 'export'],
        'settings' => ['view', 'manage'],
        'system' => ['view', 'manage'],
    ];

    /**
     * Default roles. "module.*" expands to every action of the module, "*" to everything.
     * Administrators can change non-system role permissions at runtime from the dashboard.
     *
     * @return array<string, array{name_ar: string, name_en: string, description_en: string, permissions: list<string>}>
     */
    public static function roles(): array
    {
        return [
            self::SUPER_ADMIN => [
                'name_ar' => 'مدير النظام الأعلى',
                'name_en' => 'Super Admin',
                'description_en' => 'Unrestricted access to the whole platform.',
                'permissions' => ['*'],
            ],
            'administrator' => [
                'name_ar' => 'مدير',
                'name_en' => 'Administrator',
                'description_en' => 'Runs the platform day to day; cannot edit roles or system-level settings.',
                'permissions' => self::except(['roles.create', 'roles.edit', 'roles.delete', 'system.manage']),
            ],
            'marketing-manager' => [
                'name_ar' => 'مدير التسويق',
                'name_en' => 'Marketing Manager',
                'description_en' => 'Owns content, SEO, integrations and marketing analytics.',
                'permissions' => [
                    'dashboard.view', 'analytics.*', 'leads.view', 'leads.export', 'customers.view',
                    'services.*', 'portfolio.*', 'case_studies.*', 'pages.*', 'homepage.*', 'articles.*',
                    'media.*', 'testimonials.*', 'team.view', 'navigation.*', 'messages.view',
                    'marketing.*', 'seo.*', 'products.view', 'product_categories.view',
                ],
            ],
            'sales-manager' => [
                'name_ar' => 'مدير المبيعات',
                'name_en' => 'Sales Manager',
                'description_en' => 'Manages leads, customers and quotations for the whole sales team.',
                'permissions' => [
                    'dashboard.view', 'analytics.view', 'leads.*', 'customers.*', 'tasks.*', 'quotations.*',
                    'orders.view', 'projects.view', 'messages.*', 'services.view', 'products.view', 'media.view',
                ],
            ],
            'sales-agent' => [
                'name_ar' => 'مندوب مبيعات',
                'name_en' => 'Sales Agent',
                'description_en' => 'Works the leads and quotations assigned to them.',
                'permissions' => [
                    'dashboard.view', 'leads.view_assigned', 'leads.edit_assigned', 'leads.create',
                    'tasks.view_assigned', 'tasks.edit_assigned', 'tasks.create',
                    'customers.view', 'quotations.view_assigned', 'quotations.edit_assigned', 'quotations.create',
                    'messages.view', 'services.view', 'products.view',
                ],
            ],
            'content-manager' => [
                'name_ar' => 'مدير المحتوى',
                'name_en' => 'Content Manager',
                'description_en' => 'Creates, edits and publishes all website content.',
                'permissions' => [
                    'dashboard.view', 'services.*', 'products.view', 'products.create', 'products.edit',
                    'products.publish', 'product_categories.*', 'portfolio.*', 'case_studies.*', 'pages.*',
                    'homepage.*', 'articles.*', 'media.*', 'testimonials.*', 'team.*', 'navigation.*',
                    'seo.*', 'messages.view',
                ],
            ],
            'content-editor' => [
                'name_ar' => 'محرر المحتوى',
                'name_en' => 'Content Editor',
                'description_en' => 'Edits content but cannot publish, delete or touch financial settings.',
                'permissions' => [
                    'dashboard.view', 'services.view', 'services.create', 'services.edit',
                    'portfolio.view', 'portfolio.create', 'portfolio.edit',
                    'case_studies.view', 'case_studies.create', 'case_studies.edit',
                    'pages.view', 'pages.create', 'pages.edit',
                    'articles.view', 'articles.create', 'articles.edit',
                    'media.view', 'media.create', 'testimonials.view', 'testimonials.create', 'testimonials.edit',
                ],
            ],
            'project-manager' => [
                'name_ar' => 'مدير المشاريع',
                'name_en' => 'Project Manager',
                'description_en' => 'Plans and delivers client projects.',
                'permissions' => [
                    'dashboard.view', 'projects.*', 'tasks.*', 'customers.view', 'orders.view', 'quotations.view',
                    'media.view', 'media.create',
                ],
            ],
            'finance-manager' => [
                'name_ar' => 'مدير الحسابات',
                'name_en' => 'Finance Manager',
                'description_en' => 'Manages orders, payments and pricing; cannot change permissions.',
                'permissions' => [
                    'dashboard.view', 'analytics.*', 'orders.view', 'orders.edit', 'orders.export',
                    'payments.*', 'quotations.view', 'quotations.approve', 'quotations.export',
                    'customers.view', 'pricing.*',
                ],
            ],
            'support-agent' => [
                'name_ar' => 'خدمة العملاء',
                'name_en' => 'Support Agent',
                'description_en' => 'Answers customer messages and looks up orders.',
                'permissions' => [
                    'dashboard.view', 'messages.view', 'messages.edit', 'customers.view', 'orders.view',
                    'leads.view_assigned',
                ],
            ],
            self::CUSTOMER => [
                'name_ar' => 'عميل',
                'name_en' => 'Customer',
                'description_en' => 'Storefront account. No access to the admin dashboard.',
                'permissions' => [],
            ],
        ];
    }

    /** @return list<string> every permission key */
    public static function all(): array
    {
        $keys = [];
        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action) {
                $keys[] = "{$module}.{$action}";
            }
        }

        return $keys;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    /**
     * Expand wildcards ("*" and "module.*") into concrete keys.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $keys = [];
        foreach ($patterns as $pattern) {
            if ($pattern === '*') {
                $keys = array_merge($keys, self::all());
            } elseif (str_ends_with($pattern, '.*')) {
                $module = substr($pattern, 0, -2);
                foreach (self::MODULES[$module] ?? [] as $action) {
                    $keys[] = "{$module}.{$action}";
                }
            } elseif (self::exists($pattern)) {
                $keys[] = $pattern;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Every permission except the given keys.
     *
     * @param  list<string>  $excluded
     * @return list<string>
     */
    public static function except(array $excluded): array
    {
        return array_values(array_diff(self::all(), $excluded));
    }

    /** @return list<array{module: string, actions: list<array{key: string, action: string}>}> */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::MODULES as $module => $actions) {
            $out[] = [
                'module' => $module,
                'actions' => array_map(fn (string $a) => ['key' => "{$module}.{$a}", 'action' => $a], $actions),
            ];
        }

        return $out;
    }
}
