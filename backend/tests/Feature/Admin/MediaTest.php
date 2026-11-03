<?php

namespace Tests\Feature\Admin;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_image_upload_is_sniffed_reencoded_and_gets_variants(): void
    {
        $editor = $this->staffWithRole('content-manager');

        $res = $this->actingAs($editor)->post('/api/v1/admin/media/upload', [
            'file' => UploadedFile::fake()->image('hero.jpg', 2400, 1200), 'alt_en' => 'Hero', 'alt_ar' => 'صورة',
        ], ['Accept' => 'application/json'])->assertCreated();

        $media = Media::query()->firstOrFail();
        $this->assertSame('image', $media->kind);
        $this->assertSame(2400, $media->width);
        $this->assertArrayHasKey('thumb', $media->variants);
        $this->assertArrayHasKey('large', $media->variants);
        Storage::disk('public')->assertExists($media->path);
        Storage::disk('public')->assertExists($media->variants['thumb']);
        $res->assertJsonPath('data.0.alt_en', 'Hero');
    }

    public function test_disguised_and_disallowed_files_are_rejected(): void
    {
        $editor = $this->staffWithRole('content-manager');

        // PHP code with an image extension: the real content type is checked, not the name.
        $fake = UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo 1;');
        $this->actingAs($editor)->post('/api/v1/admin/media/upload', ['file' => $fake], ['Accept' => 'application/json'])->assertStatus(422);

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($editor)->post('/api/v1/admin/media/upload', ['file' => $svg], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Media::query()->count());
    }

    public function test_upload_requires_permission(): void
    {
        $agent = $this->staffWithRole('sales-agent');
        $this->actingAs($agent)->post('/api/v1/admin/media/upload', ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_media_in_use_cannot_be_deleted_until_released(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $id = $this->actingAs($manager)->post('/api/v1/admin/media/upload', ['file' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])
            ->json('data.0.id');

        DB::table('mediables')->insert(['media_id' => $id, 'mediable_type' => 'user', 'mediable_id' => 1, 'collection' => 'avatar', 'sort_order' => 0]);
        $this->actingAs($manager)->getJson("/api/v1/admin/media/{$id}/usages")->assertOk()->assertJsonPath('data.0.collection', 'avatar');
        $this->actingAs($manager)->deleteJson("/api/v1/admin/media/{$id}")->assertStatus(409);

        DB::table('mediables')->where('media_id', $id)->delete();
        $path = Media::query()->find($id)->path;
        $this->actingAs($manager)->deleteJson("/api/v1/admin/media/{$id}")->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_replace_keeps_the_id_and_removes_old_files(): void
    {
        $manager = $this->staffWithRole('content-manager');
        $id = $this->actingAs($manager)->post('/api/v1/admin/media/upload', ['file' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])->json('data.0.id');
        $old = Media::query()->find($id)->path;

        $this->actingAs($manager)->post("/api/v1/admin/media/{$id}/replace", ['file' => UploadedFile::fake()->image('b.jpg', 500, 400)], ['Accept' => 'application/json'])->assertOk();

        $fresh = Media::query()->find($id);
        $this->assertNotSame($old, $fresh->path);
        $this->assertSame(500, $fresh->width);
        Storage::disk('public')->assertMissing($old);
    }
}
