<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use Database\Seeders\ContentSeeder;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    private const PUB = '/api/v1/public';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketsSeeder::class);
        Storage::fake('local');
    }

    private function brief(array $over = []): array
    {
        return array_merge(['name' => 'Omar', 'company' => 'ACME', 'email' => 'omar@example.com', 'phone' => '+966500000000', 'country' => 'SA',
            'description' => 'We need a brand identity and ads.', 'consent' => true, 'attribution' => ['utm_source' => 'instagram', 'utm_campaign' => 'launch']], $over);
    }

    public function test_project_brief_creates_lead_with_source_attribution_and_private_files(): void
    {
        $this->seed(ContentSeeder::class);
        $serviceId = \App\Models\Service::first()->id;
        $pdf = UploadedFile::fake()->createWithContent('brief.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $res = $this->post(self::PUB.'/project-requests', $this->brief(['service_id' => $serviceId, 'attachments' => [$pdf], 'budget' => '5000-10000 USD']), ['Accept' => 'application/json'])->assertCreated();
        $this->assertStringStartsWith('LEAD-', $res->json('data.reference'));

        $lead = Lead::firstOrFail();
        $this->assertSame('instagram', $lead->source);
        $this->assertSame('new', $lead->status);
        $this->assertSame('SA', $lead->country_code);
        $this->assertSame('launch', $lead->attribution['utm_campaign']);
        $this->assertSame(1, $lead->attachments()->count());
        $this->assertTrue(DB::table('crm_activities')->where('subject_id', $lead->id)->exists());
    }

    public function test_project_brief_validation_honeypot_and_file_type(): void
    {
        $this->postJson(self::PUB.'/project-requests', ['name' => 'x'])->assertUnprocessable();
        $this->postJson(self::PUB.'/project-requests', $this->brief(['website' => 'http://spam']))->assertCreated();
        $this->assertSame(0, Lead::count());

        $exe = UploadedFile::fake()->createWithContent('run.exe', "\x7fELF\x02\x01\x01\x00".str_repeat("\x00\x01\x02\x03", 64));
        $this->post(self::PUB.'/project-requests', $this->brief(['attachments' => [$exe]]), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, Lead::count());
        $this->postJson(self::PUB.'/project-requests', $this->brief(['country' => 'ZZ']))->assertUnprocessable()->assertJsonValidationErrors('country');
    }

    public function test_sales_agent_sees_only_assigned_leads_and_owns_what_they_create(): void
    {
        $agent = $this->staffWithRole('sales-agent');
        $manager = $this->staffWithRole('sales-manager');
        $mine = Lead::create(['name' => 'Mine', 'email' => 'a@x.com']);
        $mine->forceFill(['assigned_to' => $agent->id])->save();
        $other = Lead::create(['name' => 'Other', 'email' => 'b@x.com']);

        $names = collect($this->actingAs($agent)->getJson(self::ADMIN.'/leads')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Mine'], $names);
        $this->actingAs($agent)->getJson(self::ADMIN."/leads/{$other->id}")->assertNotFound();
        $this->actingAs($agent)->putJson(self::ADMIN."/leads/{$other->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($agent)->deleteJson(self::ADMIN."/leads/{$mine->id}")->assertForbidden();

        $created = $this->actingAs($agent)->postJson(self::ADMIN.'/leads', ['name' => 'New', 'phone' => '+201000000001'])->assertCreated();
        $this->assertSame($agent->id, $created->json('data.assigned_to'));

        // agents cannot hand leads to someone else; managers can
        $this->actingAs($agent)->putJson(self::ADMIN."/leads/{$mine->id}", ['assigned_to' => $manager->id])->assertForbidden();
        $this->actingAs($manager)->putJson(self::ADMIN."/leads/{$mine->id}", ['assigned_to' => $manager->id])->assertOk();
        $this->assertCount(3, $this->actingAs($manager)->getJson(self::ADMIN.'/leads')->json('data'));
    }

    public function test_pipeline_status_changes_are_logged_and_lost_needs_reason(): void
    {
        $manager = $this->staffWithRole('sales-manager');
        $lead = Lead::create(['name' => 'L', 'email' => 'l@x.com']);

        $this->actingAs($manager)->postJson(self::ADMIN."/leads/{$lead->id}/status", ['status' => 'qualified'])->assertOk()->assertJsonPath('data.status', 'qualified');
        $this->actingAs($manager)->postJson(self::ADMIN."/leads/{$lead->id}/status", ['status' => 'lost'])->assertUnprocessable()->assertJsonValidationErrors('lost_reason');
        $this->actingAs($manager)->postJson(self::ADMIN."/leads/{$lead->id}/status", ['status' => 'lost', 'lost_reason' => 'Budget'])->assertOk();
        $this->actingAs($manager)->postJson(self::ADMIN."/leads/{$lead->id}/status", ['status' => 'bogus'])->assertUnprocessable();

        $timeline = $this->actingAs($manager)->getJson(self::ADMIN."/leads/{$lead->id}/activities")->assertOk()->json('data');
        $this->assertSame(['status', 'status'], collect($timeline)->pluck('type')->all());
        $this->assertTrue(DB::table('audit_logs')->where('event', 'lead.updated')->exists());
    }

    public function test_kanban_board_and_source_analytics(): void
    {
        $manager = $this->staffWithRole('sales-manager');
        foreach ([['A', 'instagram', 'won'], ['B', 'instagram', 'new'], ['C', 'google', 'new'], ['D', 'website', 'lost']] as [$n, $src, $st]) {
            Lead::create(['name' => $n, 'email' => strtolower($n).'@x.com'])->forceFill(['source' => $src, 'status' => $st])->save();
        }
        $board = collect($this->actingAs($manager)->getJson(self::ADMIN.'/leads/board')->assertOk()->json('data'))->keyBy('status');
        $this->assertSame(7, $board->count());
        $this->assertSame(2, $board['new']['total']);
        $this->assertSame(1, $board['won']['total']);

        $a = $this->actingAs($manager)->getJson(self::ADMIN.'/leads/analytics')->assertOk()->json('data');
        $this->assertSame(4, $a['total']);
        $this->assertEquals(25.0, $a['conversion_rate']);
        $ig = collect($a['by_source'])->firstWhere('source', 'instagram');
        $this->assertEquals(2, $ig['total']);
        $this->assertEquals(1, $ig['won']);
    }

    public function test_followup_schedules_task_and_contact_log_stamps_last_followup(): void
    {
        $agent = $this->staffWithRole('sales-agent');
        $lead = Lead::create(['name' => 'L', 'email' => 'l@x.com']);
        $lead->forceFill(['assigned_to' => $agent->id])->save();

        $this->actingAs($agent)->postJson(self::ADMIN."/leads/{$lead->id}/follow-up", ['next_followup_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
        $when = now()->addDays(2)->toIso8601String();
        $this->actingAs($agent)->postJson(self::ADMIN."/leads/{$lead->id}/follow-up", ['next_followup_at' => $when, 'note' => 'Send proposal'])->assertOk();
        $task = Task::firstOrFail();
        $this->assertSame($agent->id, $task->assigned_to);
        $this->assertSame('lead', $task->taskable_type);

        $this->actingAs($agent)->postJson(self::ADMIN."/leads/{$lead->id}/activities", ['type' => 'call', 'body' => 'Spoke for 10 min'])->assertCreated();
        $this->assertNotNull($lead->fresh()->last_followup_at);
        $this->actingAs($agent)->postJson(self::ADMIN."/leads/{$lead->id}/activities", ['type' => 'bogus', 'body' => 'x'])->assertUnprocessable();

        // task scoping: agent sees own tasks and can complete them, but not others'
        $otherTask = Task::create(['title' => 'Not mine']);
        $this->assertCount(1, $this->actingAs($agent)->getJson(self::ADMIN.'/tasks')->json('data'));
        $this->actingAs($agent)->postJson(self::ADMIN."/tasks/{$task->id}/complete")->assertOk()->assertJsonPath('data.status', 'done');
        $this->actingAs($agent)->postJson(self::ADMIN."/tasks/{$otherTask->id}/complete")->assertNotFound();
    }

    public function test_convert_lead_to_customer_reuses_existing_by_email(): void
    {
        $manager = $this->staffWithRole('sales-manager');
        $lead = Lead::create(['name' => 'Omar', 'company' => 'ACME', 'email' => 'omar@example.com']);
        $res = $this->actingAs($manager)->postJson(self::ADMIN."/leads/{$lead->id}/convert")->assertCreated();
        $this->assertSame($res->json('data.id'), $lead->fresh()->customer_id);

        $again = Lead::create(['name' => 'Omar again', 'email' => 'omar@example.com']);
        $this->actingAs($manager)->postJson(self::ADMIN."/leads/{$again->id}/convert")->assertCreated();
        $this->assertSame(1, Customer::count());
        $this->assertSame(Customer::first()->id, $again->fresh()->customer_id);

        $profile = $this->actingAs($manager)->getJson(self::ADMIN.'/customers/'.Customer::first()->id.'/profile')->assertOk()->json('data');
        $this->assertCount(2, $profile['leads']);

        // agents cannot create customers
        $agent = $this->staffWithRole('sales-agent');
        $mine = Lead::create(['name' => 'M', 'email' => 'm@x.com']);
        $mine->forceFill(['assigned_to' => $agent->id])->save();
        $this->actingAs($agent)->postJson(self::ADMIN."/leads/{$mine->id}/convert")->assertForbidden();
    }

    public function test_customer_crud_unique_email_and_exports(): void
    {
        $manager = $this->staffWithRole('sales-manager');
        $this->actingAs($manager)->postJson(self::ADMIN.'/customers', ['name' => 'C1', 'email' => 'c@x.com', 'country_code' => 'EG'])->assertCreated();
        $this->actingAs($manager)->postJson(self::ADMIN.'/customers', ['name' => 'C2', 'email' => 'c@x.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->actingAs($manager)->postJson(self::ADMIN.'/leads', ['name' => 'NoContact'])->assertUnprocessable();
        $csv = $this->actingAs($manager)->get(self::ADMIN.'/customers/export')->assertOk();
        $this->assertStringContainsString('c@x.com', $csv->streamedContent());
        $this->actingAs($this->staffWithRole('support-agent'))->getJson(self::ADMIN.'/leads')->assertOk(); // view_assigned only
        $this->actingAs($this->staffWithRole('content-editor'))->getJson(self::ADMIN.'/leads')->assertForbidden();
    }

    public function test_lead_attachments_are_private_to_authorised_staff(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('brief.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->post(self::PUB.'/project-requests', $this->brief(['attachments' => [$pdf]]), ['Accept' => 'application/json'])->assertCreated();
        $lead = Lead::first();
        $att = $lead->attachments()->first();

        $manager = $this->staffWithRole('sales-manager');
        $list = $this->actingAs($manager)->getJson(self::ADMIN."/leads/{$lead->id}/attachments")->assertOk();
        $list->assertJsonMissingPath('data.0.path');
        $this->actingAs($manager)->get(self::ADMIN."/leads/{$lead->id}/attachments/{$att->id}")->assertOk();

        $agent = $this->staffWithRole('sales-agent'); // not assigned
        $this->actingAs($agent)->get(self::ADMIN."/leads/{$lead->id}/attachments/{$att->id}")->assertNotFound();
    }
}
