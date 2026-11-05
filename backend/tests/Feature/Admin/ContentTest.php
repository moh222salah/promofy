<?php

namespace Tests\Feature\Admin;

use App\Models\CaseStudy;
use App\Models\Media;
use App\Models\Page;
use App\Models\Service;
use App\Models\Testimonial;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/admin';

    private function serviceBody(array $over = []): array
    {
        return array_merge(['name_ar' => 'خدمة تجريبية', 'name_en' => 'Test Service'], $over);
    }

    public function test_content_manager_creates_service_with_slugs_media_and_sanitised_html(): void
    {
        $user = $this->staffWithRole('content-manager');
        $media = (new Media)->forceFill(['disk' => 'public', 'path' => 'x/a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'extension' => 'jpg', 'size' => 10, 'kind' => 'image']);
        $media->save();

        $res = $this->actingAs($user)->postJson(self::API.'/services', $this->serviceBody([
            'description_en' => '<p onclick="x()">Hi</p><script>alert(1)</script><a href="javascript:alert(1)">bad</a>',
            'features' => [['ar' => 'ميزة', 'en' => 'Feature']],
            'media' => ['main_image' => [$media->id]],
        ]))->assertCreated();

        $res->assertJsonPath('data.slug_en', 'test-service')->assertJsonPath('data.status', 'draft');
        $this->assertStringNotContainsString('script', $res->json('data.description_en'));
        $this->assertStringNotContainsString('onclick', $res->json('data.description_en'));
        $this->assertStringNotContainsString('javascript:', $res->json('data.description_en'));
        $this->assertSame($media->id, $res->json('data.media.main_image.0.id'));
        $this->assertTrue($media->fresh()->isInUse());

        // a second service with the same name gets a unique slug
        $this->actingAs($user)->postJson(self::API.'/services', $this->serviceBody())->assertCreated()->assertJsonPath('data.slug_en', 'test-service-2');
    }

    public function test_pricing_rules_and_validation(): void
    {
        $user = $this->staffWithRole('content-manager');
        $this->actingAs($user)->postJson(self::API.'/services', $this->serviceBody(['pricing_type' => 'range']))
            ->assertUnprocessable()->assertJsonValidationErrors(['price_from', 'price_to', 'currency_code']);
        $this->actingAs($user)->postJson(self::API.'/services', ['name_en' => 'Only EN'])->assertUnprocessable()->assertJsonValidationErrors('name_ar');
    }

    public function test_editor_cannot_publish_but_manager_can(): void
    {
        $editor = $this->staffWithRole('content-editor');
        $created = $this->actingAs($editor)->postJson(self::API.'/services', $this->serviceBody())->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($editor)->putJson(self::API."/services/{$id}", ['status' => 'published'])->assertForbidden();
        $this->actingAs($editor)->postJson(self::API."/services/{$id}/publish")->assertForbidden();
        $this->actingAs($editor)->deleteJson(self::API."/services/{$id}")->assertForbidden();

        $manager = $this->staffWithRole('content-manager');
        $this->actingAs($manager)->postJson(self::API."/services/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertNotNull(Service::find($id)->published_at);
        $this->actingAs($manager)->postJson(self::API."/services/{$id}/unpublish")->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_sales_agent_has_no_access_to_content_admin(): void
    {
        $agent = $this->staffWithRole('sales-agent');
        $this->actingAs($agent)->getJson(self::API.'/services')->assertOk(); // services.view
        $this->actingAs($agent)->postJson(self::API.'/services', $this->serviceBody())->assertForbidden();
        $this->actingAs($agent)->getJson(self::API.'/pages')->assertForbidden();
    }

    public function test_guests_are_rejected_from_content_admin(): void
    {
        $this->getJson(self::API.'/pages')->assertUnauthorized();
        $this->postJson(self::API.'/services', $this->serviceBody())->assertUnauthorized();
    }

    public function test_public_api_shows_only_live_content(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $live = Service::create(['name_ar' => 'ا', 'name_en' => 'Live', 'slug_ar' => 'live-ar', 'slug_en' => 'live', 'status' => 'published', 'published_at' => now()->subMinute()]);
        Service::create(['name_ar' => 'ب', 'name_en' => 'Draft', 'slug_ar' => 'draft-ar', 'slug_en' => 'draft', 'status' => 'draft']);
        Service::create(['name_ar' => 'ج', 'name_en' => 'Scheduled', 'slug_ar' => 'sched-ar', 'slug_en' => 'scheduled', 'status' => 'published', 'published_at' => now()->addDay()]);
        Service::create(['name_ar' => 'د', 'name_en' => 'Archived', 'slug_ar' => 'arch-ar', 'slug_en' => 'archived', 'status' => 'archived']);

        $list = $this->getJson('/api/v1/public/services')->assertOk();
        $this->assertSame(['live'], collect($list->json('data'))->pluck('slug_en')->all());
        $this->getJson('/api/v1/public/services/live')->assertOk()->assertJsonPath('data.name_en', 'Live');
        $this->getJson('/api/v1/public/services/live-ar')->assertOk();
        foreach (['draft', 'scheduled', 'archived'] as $slug) {
            $this->getJson("/api/v1/public/services/{$slug}")->assertNotFound();
        }
        $this->assertArrayNotHasKey('status', $list->json('data.0'));
        $this->assertNotNull($manager);
        $this->assertTrue($live->isLive());
    }

    public function test_case_study_results_must_be_verified_and_client_hidden_without_approval(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $body = ['title_ar' => 'دراسة', 'title_en' => 'Study', 'client_name' => 'Secret Co', 'client_approved' => false,
            'results' => [['label_ar' => 'نتيجة', 'label_en' => 'Result', 'value' => '10']], 'status' => 'published'];

        $this->actingAs($manager)->postJson(self::API.'/case-studies', $body)->assertUnprocessable()->assertJsonValidationErrors('results_verified');

        $this->actingAs($manager)->postJson(self::API.'/case-studies', $body + ['results_verified' => true])->assertCreated();
        $public = $this->getJson('/api/v1/public/case-studies/study')->assertOk();
        $public->assertJsonPath('data.client_name', null);
        $this->assertCount(1, $public->json('data.results'));

        CaseStudy::query()->update(['results_verified' => false, 'status' => 'draft']);
        $this->assertSame(0, CaseStudy::query()->published()->count());
    }

    public function test_testimonials_need_consent_to_publish_and_stay_hidden_otherwise(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $body = ['author_name' => 'A. Client', 'quote_en' => 'Great work', 'status' => 'published'];
        $this->actingAs($manager)->postJson(self::API.'/testimonials', $body)->assertUnprocessable()->assertJsonValidationErrors('consent_given');
        $this->actingAs($manager)->postJson(self::API.'/testimonials', $body + ['consent_given' => true])->assertCreated();
        $this->assertCount(1, $this->getJson('/api/v1/public/testimonials')->json('data'));

        Testimonial::query()->update(['consent_given' => false]);
        $this->assertCount(0, $this->getJson('/api/v1/public/testimonials')->json('data'));
    }

    public function test_publish_and_bulk_publish_actions_cannot_bypass_consent_and_verification_rules(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $this->actingAs($manager);

        $t = $this->postJson(self::API.'/testimonials', ['author_name' => 'A', 'quote_en' => 'Great', 'consent_given' => false])->assertCreated()->json('data.id');
        $this->postJson(self::API."/testimonials/$t/publish")->assertUnprocessable()->assertJsonValidationErrors('consent_given');
        $this->postJson(self::API.'/testimonials/bulk', ['action' => 'publish', 'ids' => [$t]])->assertUnprocessable();
        $this->assertSame('draft', Testimonial::findOrFail($t)->status);
        $this->putJson(self::API."/testimonials/$t", ['consent_given' => true])->assertOk();
        $this->postJson(self::API."/testimonials/$t/publish")->assertOk();
        $this->assertSame('published', Testimonial::findOrFail($t)->status);

        $c = $this->postJson(self::API.'/case-studies', ['title_ar' => 'د', 'title_en' => 'S', 'results' => [['label_ar' => 'ن', 'label_en' => 'R', 'value' => '1']], 'results_verified' => false])->assertCreated()->json('data.id');
        $this->postJson(self::API."/case-studies/$c/publish")->assertUnprocessable()->assertJsonValidationErrors('results_verified');
        $this->assertSame('draft', CaseStudy::findOrFail($c)->status);
        $this->putJson(self::API."/case-studies/$c", ['results_verified' => true])->assertOk();
        $this->postJson(self::API."/case-studies/$c/publish")->assertOk();
    }

    public function test_page_revisions_are_recorded_and_restorable(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $id = $this->actingAs($manager)->postJson(self::API.'/pages', ['title_ar' => 'صفحة', 'title_en' => 'Page', 'content_en' => '<p>v1</p>'])->assertCreated()->json('data.id');
        $this->actingAs($manager)->putJson(self::API."/pages/{$id}", ['content_en' => '<p>v2</p>'])->assertOk();
        $this->actingAs($manager)->putJson(self::API."/pages/{$id}", ['content_en' => '<p>v3</p>'])->assertOk();

        $revs = $this->actingAs($manager)->getJson(self::API."/pages/{$id}/revisions")->assertOk()->json('data');
        $this->assertCount(2, $revs);

        $oldest = collect($revs)->last()['id'];
        $this->actingAs($manager)->postJson(self::API."/pages/{$id}/revisions/{$oldest}/restore")->assertOk()->assertJsonPath('data.content_en', '<p>v1</p>');
        $this->assertSame('<p>v1</p>', Page::find($id)->content_en);
    }

    public function test_system_pages_cannot_be_deleted_and_duplicates_start_as_draft(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $this->seed(ContentSeeder::class);
        $about = Page::where('slug_en', 'about')->firstOrFail();
        $this->actingAs($manager)->deleteJson(self::API."/pages/{$about->id}")->assertStatus(409);

        $service = Service::first();
        $service->update(['status' => 'published', 'published_at' => now()]);
        $copy = $this->actingAs($manager)->postJson(self::API."/services/{$service->id}/duplicate")->assertCreated();
        $copy->assertJsonPath('data.status', 'draft');
        $this->assertNotSame($service->slug_en, $copy->json('data.slug_en'));
    }

    public function test_homepage_draft_preview_publish_flow(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $this->seed(ContentSeeder::class);
        $service = Service::first();

        $draft = $this->actingAs($manager)->getJson(self::API.'/homepage')->assertOk()->json('data.sections');
        $this->assertSame('hero', $draft[0]['type']);

        // before any publish the public site renders the safe defaults
        $this->assertSame('hero', $this->getJson('/api/v1/public/homepage')->json('data.sections.0.type'));

        foreach ($draft as &$section) {
            if ($section['type'] === 'hero') {
                $section['title_en'] = 'Edited hero';
            }
            if ($section['type'] === 'featured_services') {
                $section['settings'] = ['ids' => [$service->id]];
            }
        }
        unset($section);
        $this->actingAs($manager)->putJson(self::API.'/homepage', ['sections' => $draft])->assertOk();

        $preview = $this->actingAs($manager)->getJson(self::API.'/homepage/preview')->assertOk()->json('data');
        $this->assertSame('Edited hero', $preview[0]['title_en']);
        $this->assertNotSame('Edited hero', $this->getJson('/api/v1/public/homepage')->json('data.sections.0.title_en'));

        $this->actingAs($manager)->postJson(self::API.'/homepage/publish')->assertOk();
        $public = $this->getJson('/api/v1/public/homepage')->assertOk()->json('data.sections');
        $this->assertSame('Edited hero', $public[0]['title_en']);
        $featured = collect($public)->firstWhere('type', 'featured_services');
        $this->assertSame($service->id, $featured['items'][0]['id']);

        // disabled sections are not served
        $this->assertNull(collect($public)->firstWhere('type', 'trust'));

        $editor = $this->staffWithRole('content-editor');
        $this->actingAs($editor)->postJson(self::API.'/homepage/publish')->assertForbidden();
        $this->actingAs($manager)->putJson(self::API.'/homepage', ['sections' => [['id' => 'x', 'type' => 'evil', 'enabled' => true]]])->assertUnprocessable();
    }

    public function test_navigation_builder_round_trip_and_visibility(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $payload = ['items' => [
            ['label_ar' => 'الرئيسية', 'label_en' => 'Home', 'url' => '/'],
            ['label_ar' => 'خدمات', 'label_en' => 'Services', 'url' => '/services', 'children' => [['label_ar' => 'فرعي', 'label_en' => 'Sub', 'url' => '/services/x']]],
            ['label_ar' => 'مخفي', 'label_en' => 'Hidden', 'url' => '/h', 'is_visible' => false],
        ]];
        $this->actingAs($manager)->putJson(self::API.'/navigation/header', $payload)->assertOk();
        $tree = $this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header');
        $this->assertCount(2, $tree);
        $this->assertSame('Sub', $tree[1]['children'][0]['label_en']);

        $this->actingAs($manager)->putJson(self::API.'/navigation/header', ['items' => [['label_ar' => 'x', 'label_en' => 'x', 'url' => 'javascript:alert(1)']]])->assertUnprocessable();
    }

    public function test_contact_form_stores_message_with_attribution_and_blocks_bots(): void
    {
        $body = ['name' => 'Sara', 'email' => 'sara@example.com', 'message' => 'Hello there', 'consent' => true, 'attribution' => ['utm_source' => 'instagram']];
        $this->postJson('/api/v1/public/contact', $body)->assertCreated()->assertJsonPath('data.received', true);
        $this->assertDatabaseHas('contact_messages', ['email' => 'sara@example.com', 'status' => 'new']);
        $this->assertSame('instagram', DB::table('contact_messages')->first() ? json_decode(DB::table('contact_messages')->first()->attribution, true)['utm_source'] : null);

        $this->postJson('/api/v1/public/contact', $body + ['website' => 'http://spam'])->assertCreated();
        $this->assertSame(1, DB::table('contact_messages')->count());

        $this->postJson('/api/v1/public/contact', ['name' => 'x'])->assertUnprocessable();

        $support = $this->staffWithRole('support-agent');
        $id = DB::table('contact_messages')->value('id');
        $this->actingAs($support)->putJson(self::API."/contact-messages/{$id}", ['status' => 'read'])->assertOk()->assertJsonPath('data.status', 'read');
        $this->actingAs($support)->deleteJson(self::API."/contact-messages/{$id}")->assertForbidden();
    }

    public function test_activity_is_audited(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $id = $this->actingAs($manager)->postJson(self::API.'/services', $this->serviceBody())->json('data.id');
        $this->actingAs($manager)->postJson(self::API."/services/{$id}/publish")->assertOk();
        $this->assertTrue(DB::table('audit_logs')->where('event', 'service.published')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('event', 'service.created')->exists());
    }
}
