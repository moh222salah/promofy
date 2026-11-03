<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_framework_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_api_health_endpoint_reports_service_status(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'promofy-api')
            ->assertJsonStructure(['status', 'service', 'version', 'time']);
    }

    public function test_unknown_api_routes_return_json_not_html(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
