<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Task;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketsSeeder::class);
    }

    private function create(array $over = [], $user = null): array
    {
        $user ??= $this->staffWithRole('project-manager');
        $res = $this->actingAs($user)->postJson(self::ADMIN.'/projects', array_merge(['name' => 'Rebrand', 'start_date' => '2026-12-01', 'due_date' => '2026-12-31'], $over))->assertCreated();

        return [$user, $res->json('data.id'), $res];
    }

    public function test_create_with_members_milestones_and_validation(): void
    {
        $member = $this->staffWithRole('content-editor');
        [$pm, $id, $res] = $this->create([
            'members' => [['user_id' => $member->id, 'role' => 'Designer']],
            'milestones' => [['title' => 'Kick-off', 'due_date' => '2026-12-03'], ['title' => 'Delivery', 'due_date' => '2026-12-30']],
        ]);
        $this->assertSame($pm->id, $res->json('data.manager_id'));
        $this->assertCount(1, $res->json('data.members'));
        $this->assertSame(['Kick-off', 'Delivery'], collect($res->json('data.milestones'))->pluck('title')->all());

        $this->actingAs($pm)->postJson(self::ADMIN.'/projects', ['name' => 'Bad', 'start_date' => '2026-12-10', 'due_date' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors('due_date');

        // milestone completion stamps a date; removing from payload deletes it
        $mid = $res->json('data.milestones.0.id');
        $upd = $this->actingAs($pm)->putJson(self::ADMIN."/projects/{$id}", ['milestones' => [['id' => $mid, 'title' => 'Kick-off', 'status' => 'done']]])->assertOk();
        $this->assertCount(1, $upd->json('data.milestones'));
        $this->assertNotNull($upd->json('data.milestones.0.completed_at'));
    }

    public function test_progress_follows_tasks_and_status_completion(): void
    {
        [$pm, $id] = $this->create();
        $t1 = $this->actingAs($pm)->postJson(self::ADMIN.'/tasks', ['title' => 'Concepts', 'taskable_type' => 'project', 'taskable_id' => $id])->assertCreated()->json('data.id');
        $this->actingAs($pm)->postJson(self::ADMIN.'/tasks', ['title' => 'Final files', 'taskable_type' => 'project', 'taskable_id' => $id])->assertCreated();
        $this->assertSame(0, Project::find($id)->progress);

        $this->actingAs($pm)->postJson(self::ADMIN."/tasks/{$t1}/complete")->assertOk();
        $this->assertSame(50, Project::find($id)->progress);
        $this->actingAs($pm)->putJson(self::ADMIN."/projects/{$id}", ['progress' => 90])->assertOk(); // ignored: tasks drive it
        $this->assertSame(50, Project::find($id)->progress);

        $this->assertCount(2, $this->actingAs($pm)->getJson(self::ADMIN."/projects/{$id}/tasks")->json('data'));
        $done = $this->actingAs($pm)->postJson(self::ADMIN."/projects/{$id}/status", ['status' => 'completed'])->assertOk();
        $done->assertJsonPath('data.progress', 100);
        $this->assertNotNull(Project::find($id)->completed_at);
        $this->actingAs($pm)->postJson(self::ADMIN."/projects/{$id}/status", ['status' => 'nope'])->assertUnprocessable();
        $this->assertCount(1, $this->actingAs($pm)->getJson(self::ADMIN."/projects/{$id}/timeline")->json('data'));
    }

    public function test_scoping_members_see_their_projects_only(): void
    {
        $agent = $this->staffWithRole('sales-agent'); // no project permissions at all
        $this->actingAs($agent)->getJson(self::ADMIN.'/projects')->assertForbidden();

        $pm = $this->staffWithRole('project-manager');
        $this->actingAs($pm)->postJson(self::ADMIN.'/projects', ['name' => 'P1'])->assertCreated();
        $this->assertCount(1, $this->actingAs($pm)->getJson(self::ADMIN.'/projects')->json('data'));

        // custom role with only *_assigned rights
        $role = (new \App\Models\Role)->forceFill(['slug' => 'limited-pm', 'name_ar' => 'محدود', 'name_en' => 'Limited PM', 'is_system' => false]);
        $role->save();
        $role->permissions()->sync(\App\Models\Permission::whereIn('key', ['projects.view_assigned', 'projects.edit_assigned'])->pluck('id'));
        $member = \App\Models\User::factory()->create();
        $member->forceFill(['type' => 'staff'])->save();
        $member->roles()->attach($role->id);
        $member = $member->fresh();

        $mine = $this->actingAs($pm)->postJson(self::ADMIN.'/projects', ['name' => 'Shared', 'members' => [['user_id' => $member->id]]])->json('data.id');
        $names = collect($this->actingAs($member)->getJson(self::ADMIN.'/projects')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Shared'], $names);
        $this->actingAs($member)->putJson(self::ADMIN."/projects/{$mine}", ['name' => 'Renamed by member'])->assertOk();
        $other = Project::where('name', 'P1')->first();
        $this->actingAs($member)->getJson(self::ADMIN."/projects/{$other->id}")->assertNotFound();
        $this->actingAs($member)->deleteJson(self::ADMIN."/projects/{$mine}")->assertForbidden();
    }

    public function test_board_and_calendar_feeds(): void
    {
        [$pm, $id] = $this->create(['name' => 'Dec project']);
        $this->actingAs($pm)->putJson(self::ADMIN."/projects/{$id}", ['milestones' => [['title' => 'Mid', 'due_date' => '2026-12-15']]])->assertOk();
        $this->actingAs($pm)->postJson(self::ADMIN.'/tasks', ['title' => 'Due task', 'due_at' => '2026-12-20 10:00:00', 'taskable_type' => 'project', 'taskable_id' => $id])->assertCreated();

        $board = collect($this->actingAs($pm)->getJson(self::ADMIN.'/projects/board')->assertOk()->json('data'))->keyBy('status');
        $this->assertSame(6, $board->count());
        $this->assertSame(1, $board['planning']['total']);

        $cal = $this->actingAs($pm)->getJson(self::ADMIN.'/projects/calendar?from=2026-12-01&to=2026-12-31')->assertOk()->json('data');
        $this->assertCount(1, $cal['projects']);
        $this->assertCount(1, $cal['milestones']);
        $this->assertCount(1, $cal['tasks']);
        $empty = $this->actingAs($pm)->getJson(self::ADMIN.'/projects/calendar?from=2027-03-01&to=2027-03-31')->json('data');
        $this->assertCount(0, $empty['projects']);
        $this->actingAs($pm)->getJson(self::ADMIN.'/projects/calendar')->assertUnprocessable();
    }

    public function test_convert_approved_quotation_and_lead_to_project(): void
    {
        $sales = $this->staffWithRole('sales-manager');
        $q = $this->actingAs($sales)->postJson(self::ADMIN.'/quotations', [
            'customer_name' => 'ACME', 'title' => 'Campaign', 'currency_code' => 'EGP', 'items' => [['name_ar' => 'أ', 'name_en' => 'A', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json('data.id');
        $admin = $this->staffWithRole('administrator');
        $this->actingAs($admin)->postJson(self::ADMIN."/projects/from-quotation/{$q}")->assertUnprocessable(); // not approved
        $this->actingAs($sales)->postJson(self::ADMIN."/quotations/{$q}/send")->assertOk();
        $this->actingAs($sales)->postJson(self::ADMIN."/quotations/{$q}/approve")->assertOk();
        $p = $this->actingAs($admin)->postJson(self::ADMIN."/projects/from-quotation/{$q}")->assertCreated();
        $p->assertJsonPath('data.name', 'Campaign')->assertJsonPath('data.quotation_id', $q);
        $this->actingAs($admin)->postJson(self::ADMIN."/projects/from-quotation/{$q}")->assertUnprocessable(); // once only

        $lead = Lead::create(['name' => 'Sara', 'company' => 'Zed', 'email' => 'sara@zed.test']);
        $res = $this->actingAs($admin)->postJson(self::ADMIN."/projects/from-lead/{$lead->id}")->assertCreated();
        $this->assertNotNull($lead->fresh()->customer_id);
        $this->assertSame($lead->fresh()->customer_id, $res->json('data.customer_id'));
    }

    public function test_internal_notes_and_audit_and_export(): void
    {
        [$pm, $id] = $this->create();
        $this->actingAs($pm)->postJson(self::ADMIN."/projects/{$id}/notes", ['body' => 'Client prefers blue'])->assertCreated();
        $this->assertTrue(DB::table('audit_logs')->where('event', 'project.created')->exists());
        $csv = $this->actingAs($pm)->get(self::ADMIN.'/projects/export')->assertOk();
        $this->assertStringContainsString('Rebrand', $csv->streamedContent());
        $this->actingAs($this->staffWithRole('support-agent'))->getJson(self::ADMIN.'/projects')->assertForbidden();
    }
}
