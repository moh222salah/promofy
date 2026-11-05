<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Notifications\PlatformEvent;
use App\Services\Settings\SettingsService;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    private const PUB = '/api/v1/public';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketsSeeder::class);
    }

    private function brief(): array
    {
        return ['name' => 'Omar', 'email' => 'omar@example.com', 'phone' => '+966500000000', 'country' => 'SA', 'description' => 'Need a brand identity please.', 'consent' => true];
    }

    public function test_new_lead_notifies_only_staff_with_the_right_permission(): void
    {
        $sales = $this->staffWithRole('sales-manager');
        $content = $this->staffWithRole('content-editor');
        $this->postJson(self::PUB.'/project-requests', $this->brief())->assertCreated();

        $this->assertSame(2, $sales->notifications()->count()); // lead.created + quotation.requested
        $this->assertSame(0, $content->notifications()->count());
        $first = $this->actingAs($sales)->getJson(self::ADMIN.'/notifications')->assertOk();
        $this->assertSame(2, $first->json('meta.unread'));
        $this->assertContains('lead.created', collect($first->json('data'))->pluck('event')->all());
        $this->assertStringContainsString('Omar', $first->json('data.0.body_en'));
        $this->assertStringContainsString('Omar', $first->json('data.0.body_ar'));
    }

    public function test_inbox_is_private_and_read_state_works(): void
    {
        $sales = $this->staffWithRole('sales-manager');
        $other = $this->staffWithRole('sales-manager');
        $this->postJson(self::PUB.'/project-requests', $this->brief())->assertCreated();

        $id = $this->actingAs($sales)->getJson(self::ADMIN.'/notifications')->json('data.0.id');
        $this->actingAs($other)->postJson(self::ADMIN."/notifications/{$id}/read")->assertNotFound(); // not theirs
        $this->actingAs($sales)->postJson(self::ADMIN."/notifications/{$id}/read")->assertOk();
        $this->assertSame(1, $this->actingAs($sales)->getJson(self::ADMIN.'/notifications/unread-count')->json('data.unread'));
        $this->actingAs($sales)->postJson(self::ADMIN.'/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->actingAs($sales)->getJson(self::ADMIN.'/notifications/unread-count')->json('data.unread'));
        $this->assertCount(0, $this->actingAs($sales)->getJson(self::ADMIN.'/notifications?unread=1')->json('data'));
    }

    public function test_channels_templates_and_extra_recipients_are_configurable(): void
    {
        Notification::fake();
        $sales = $this->staffWithRole('sales-manager');
        $s = app(SettingsService::class);
        $s->update('notifications', ['recipients' => ['lead.created' => ['boss@example.com', 'not-an-email']], 'templates' => ['lead.created' => ['subject_en' => 'HOT LEAD', 'body_en' => ':name is knocking']]]);
        $this->postJson(self::PUB.'/project-requests', $this->brief())->assertCreated();

        Notification::assertSentTo($sales, PlatformEvent::class, function (PlatformEvent $n) {
            return $n->event === 'lead.created' && $n->texts()['subject_en'] === 'HOT LEAD' && $n->texts()['body_en'] === 'Omar is knocking'
                && $n->texts()['subject_ar'] === 'عميل محتمل جديد'; // untouched fields keep defaults
        });
        Notification::assertSentOnDemand(PlatformEvent::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'boss@example.com');
        Notification::assertSentOnDemandTimes(PlatformEvent::class, 1); // invalid address ignored; quotation.requested has no extra recipients
    }

    public function test_database_only_channel_skips_mail(): void
    {
        $sales = $this->staffWithRole('sales-manager');
        app(SettingsService::class)->update('notifications', ['channels' => ['database' => true, 'mail' => false, 'whatsapp' => false]]);
        $n = new PlatformEvent('lead.created', ['name' => 'X', 'source' => 'web']);
        $this->assertSame(['database'], $n->via($sales));
        app(SettingsService::class)->update('notifications', ['channels' => ['database' => true, 'mail' => true]]);
        $this->assertSame(['database', 'mail'], $n->via($sales));
    }

    public function test_business_events_fire_orders_payments_stock_messages_projects(): void
    {
        $finance = $this->staffWithRole('finance-manager');
        $manager = $this->staffWithRole('content-manager');
        $pm = $this->staffWithRole('project-manager');
        $s = app(SettingsService::class);
        $s->update('checkout', ['whatsapp_order_enabled' => true]);
        $s->update('contact', ['whatsapp_number' => '+201000000000']);

        $p = new Product;
        $p->forceFill(['type' => 'custom', 'sku' => 'S1', 'slug_ar' => 's1', 'slug_en' => 's1', 'name_ar' => 'س', 'name_en' => 'Stocky', 'price' => 10, 'currency_code' => 'EGP',
            'status' => 'published', 'published_at' => now(), 'track_stock' => true, 'stock_quantity' => 6, 'low_stock_threshold' => 5])->save();
        $token = $this->postJson(self::PUB.'/cart/items', ['product_id' => $p->id, 'quantity' => 2])->json('data.token');
        $this->postJson(self::PUB.'/checkout', ['cart_token' => $token, 'method' => 'whatsapp', 'name' => 'S', 'email' => 's@x.com', 'phone' => '+201111111111', 'country' => 'EG', 'consent' => true])->assertCreated();

        $events = fn ($u) => $u->notifications()->get()->pluck('data.event')->all();
        $this->assertContains('order.placed', $events($finance)); // orders.view
        $this->assertContains('stock.low', $events($manager));     // products.view, 6 → 4 crosses threshold 5

        $this->postJson(self::PUB.'/contact', ['name' => 'Z', 'email' => 'z@x.com', 'message' => 'Hello there', 'consent' => true])->assertCreated();
        $this->assertContains('message.received', $events($manager));

        $other = $this->staffWithRole('administrator');
        $this->actingAs($other)->postJson(self::ADMIN.'/projects', ['name' => 'Assigned', 'manager_id' => $pm->id])->assertCreated();
        $this->assertContains('project.assigned', $events($pm));
        $this->assertNotContains('project.assigned', $events($other));
    }

    public function test_event_catalog_endpoint_requires_permission(): void
    {
        $this->getJson(self::ADMIN.'/notifications')->assertUnauthorized();
        $this->actingAs($this->staffWithRole('support-agent'))->getJson(self::ADMIN.'/notifications/events')->assertForbidden();
        $this->actingAs($this->staffWithRole('administrator'))->getJson(self::ADMIN.'/notifications/events')->assertOk()->assertJsonStructure(['data' => ['lead.created' => ['subject_en']]]);
    }
}
