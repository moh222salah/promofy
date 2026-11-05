<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateOpenApiCommand;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    public function test_document_covers_every_api_route_and_marks_admin_routes_secured(): void
    {
        $doc = GenerateOpenApiCommand::document();
        $this->assertSame('3.0.3', $doc['openapi']);
        $this->assertGreaterThan(200, count($doc['paths']));
        $this->assertArrayHasKey('/admin/orders', $doc['paths']);
        $this->assertArrayHasKey('/public/payments/webhook/{gateway}', $doc['paths']);
        $this->assertNotEmpty($doc['paths']['/admin/orders']['get']['security']);
        $this->assertArrayNotHasKey('security', $doc['paths']['/public/services']['get']);

        $drift = base_path('../docs/openapi.json');
        $this->assertFileExists($drift);
        $this->assertSame(array_keys($doc['paths']), array_keys(json_decode(file_get_contents($drift), true)['paths']), 'docs/openapi.json is stale — run php artisan openapi:generate');
    }
}
