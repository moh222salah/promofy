<?php

namespace App\Support\Crud;

use Illuminate\Support\Facades\Route;

/**
 * Registers the standard admin routes for a CrudController.
 *
 * Features: options, export, reorder, bulk, duplicate, publish (publish / unpublish / archive),
 * plus the base CRUD verbs. Pass ['only' => [...]] to expose a subset (e.g. read-only modules).
 */
final class CrudRoutes
{
    public const ALL = ['index', 'show', 'store', 'update', 'destroy', 'options', 'export', 'reorder', 'bulk', 'duplicate', 'publish'];

    /**
     * @param  array{only?: list<string>, except?: list<string>}  $options
     */
    public static function register(string $uri, string $controller, array $options = []): void
    {
        $features = $options['only'] ?? self::ALL;
        $features = array_diff($features, $options['except'] ?? []);
        $has = fn (string $feature): bool => in_array($feature, $features, true);
        $name = 'admin.'.str_replace('/', '.', $uri);

        // Static segments first so they are never captured by "{id}".
        if ($has('options')) {
            Route::get("{$uri}/options", [$controller, 'options'])->name("{$name}.options");
        }
        if ($has('export')) {
            Route::get("{$uri}/export", [$controller, 'export'])->name("{$name}.export");
        }
        if ($has('reorder')) {
            Route::post("{$uri}/reorder", [$controller, 'reorder'])->name("{$name}.reorder");
        }
        if ($has('bulk')) {
            Route::post("{$uri}/bulk", [$controller, 'bulk'])->name("{$name}.bulk");
        }
        if ($has('index')) {
            Route::get($uri, [$controller, 'index'])->name("{$name}.index");
        }
        if ($has('store')) {
            Route::post($uri, [$controller, 'store'])->name("{$name}.store");
        }
        if ($has('show')) {
            Route::get("{$uri}/{id}", [$controller, 'show'])->whereNumber('id')->name("{$name}.show");
        }
        if ($has('update')) {
            Route::match(['put', 'patch'], "{$uri}/{id}", [$controller, 'update'])->whereNumber('id')->name("{$name}.update");
        }
        if ($has('destroy')) {
            Route::delete("{$uri}/{id}", [$controller, 'destroy'])->whereNumber('id')->name("{$name}.destroy");
        }
        if ($has('duplicate')) {
            Route::post("{$uri}/{id}/duplicate", [$controller, 'duplicate'])->whereNumber('id')->name("{$name}.duplicate");
        }
        if ($has('publish')) {
            foreach (['publish', 'unpublish', 'archive'] as $action) {
                Route::post("{$uri}/{id}/{$action}", [$controller, $action])->whereNumber('id')->name("{$name}.{$action}");
            }
        }
    }
}
