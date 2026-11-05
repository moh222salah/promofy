<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Builds an OpenAPI 3.0 document from the registered /api/v1 routes, so the docs can never drift from the code. */
class GenerateOpenApiCommand extends Command
{
    protected $signature = 'openapi:generate {--path= : Output file (default docs/openapi.json at the repo root)}';

    protected $description = 'Generate the OpenAPI document for the REST API';

    public function handle(): int
    {
        $doc = self::document();
        $path = $this->option('path') ?: base_path('../docs/openapi.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->info('Wrote '.count($doc['paths']).' paths to '.$path);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            $path = '/'.substr($uri, strlen('api/v1/'));
            $params = [];
            $path = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use (&$params, $route) {
                $params[] = ['name' => $m[1], 'in' => 'path', 'required' => true, 'schema' => ['type' => isset($route->wheres[$m[1]]) && $route->wheres[$m[1]] === '[0-9]+' ? 'integer' : 'string']];

                return '{'.$m[1].'}';
            }, $path);
            $segments = explode('/', trim($path, '/'));
            $isAdmin = $segments[0] === 'admin';
            $tag = $isAdmin ? 'admin/'.($segments[1] ?? 'root') : $segments[0].(($segments[0] === 'public' && isset($segments[1])) ? '/'.$segments[1] : '');
            $middleware = $route->gatherMiddleware();
            $secured = collect($middleware)->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth:') || $m === 'staff'));

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $op = [
                    'tags' => [$tag],
                    'summary' => Str::headline(strtolower($method).' '.str_replace(['{', '}', '/'], [' ', ' ', ' '], $path)),
                    'operationId' => Str::camel(strtolower($method).'_'.preg_replace('/[^a-z0-9]+/i', '_', $path)),
                    'parameters' => $params,
                    'responses' => [
                        '200' => ['description' => 'Success'],
                        '422' => ['description' => 'Validation failed — { message, errors: { field: [..] } }'],
                        '429' => ['description' => 'Rate limited'],
                    ],
                ];
                if ($secured) {
                    $op['security'] = [['sanctumCookie' => []]];
                    $op['responses']['401'] = ['description' => 'Not authenticated'];
                    $op['responses']['403'] = ['description' => 'Authenticated but lacks the required permission (enforced server-side)'];
                }
                if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                    $op['requestBody'] = ['content' => ['application/json' => ['schema' => ['type' => 'object']]]];
                }
                if ($isAdmin && $method === 'GET' && ! $params) {
                    $op['parameters'] = array_merge($op['parameters'], [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 100]],
                    ]);
                }
                $paths[$path][strtolower($method)] = $op;
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'PROMOFY API', 'version' => '1.0.0', 'description' => 'Versioned REST API. Admin endpoints use Sanctum SPA cookie auth (CSRF via /sanctum/csrf-cookie) and server-side permission checks. Guest cart/order access uses X-Cart-Token / X-Order-Token headers. Money is decimal strings in the order/payment currency; no implicit FX.'],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'components' => ['securitySchemes' => ['sanctumCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'laravel_session']]],
            'paths' => $paths,
        ];
    }
}
