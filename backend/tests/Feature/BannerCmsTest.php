<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CMS banner hero landing page:
 *  - publik hanya melihat banner visible (aktif + dalam periode tayang);
 *  - gambar disimpan di disk privat, nama hash server, divalidasi konten;
 *  - mutasi (create/update/status/reorder/delete) terproteksi role admin;
 *  - CTA URL dengan skema berbahaya (javascript:) ditolak.
 */
class BannerCmsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        return $user->refresh();
    }

    private function parent_(): User
    {
        $user = User::factory()->create(['jenjang' => 'SD']);
        $user->forceFill(['role' => 'orang_tua'])->save();
        return $user->refresh();
    }

    private function createBanner(array $overrides = []): Banner
    {
        Storage::fake('private');
        Sanctum::actingAs($this->admin());

        $response = $this->post('/api/admin/banners', array_merge([
            'title'     => 'Penerimaan Murid Baru',
            'is_active' => '1',
            'image'     => UploadedFile::fake()->image('hero.png', 800, 600),
        ], $overrides), ['Accept' => 'application/json']);

        $response->assertCreated();
        return Banner::findOrFail($response->json('id'));
    }

    public function test_public_index_lists_only_visible_banners_in_order(): void
    {
        Storage::fake('private');
        $visible = $this->createBanner(['title' => 'Slide A', 'sort_order' => '2']);
        $this->createBanner(['title' => 'Slide Nonaktif', 'sort_order' => '1', 'is_active' => '0']);
        $this->createBanner([
            'title' => 'Slide Belum Tayang',
            'starts_at' => now()->addDay()->toDateTimeString(),
        ]);
        $first = $this->createBanner(['title' => 'Slide B', 'sort_order' => '0']);

        $response = $this->getJson('/api/banners');

        $response->assertOk();
        $titles = array_column($response->json(), 'title');
        $this->assertSame(['Slide B', 'Slide A'], $titles);
        // Internal storage path never leaks to the public payload.
        $this->assertStringStartsWith('/api/banners/', $response->json('0.image_url'));
        $this->assertArrayNotHasKey('image_path', $response->json('0'));
    }

    public function test_public_image_stream_serves_visible_banner_image(): void
    {
        $banner = $this->createBanner();

        $this->get("/api/banners/{$banner->id}/image")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        Storage::disk('private')->assertExists($banner->fresh()->image_path);
    }

    public function test_public_image_stream_refuses_inactive_banner(): void
    {
        $banner = $this->createBanner(['is_active' => '0']);

        $this->get("/api/banners/{$banner->id}/image")->assertNotFound();
    }

    public function test_non_admin_cannot_mutate_banners(): void
    {
        Storage::fake('private');
        $banner = $this->createBanner();

        Sanctum::actingAs($this->parent_());

        $this->post('/api/admin/banners', [
            'title' => 'X',
            'image' => UploadedFile::fake()->image('x.png', 10, 10),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->post("/api/admin/banners/{$banner->id}", [
            'title' => 'Diretas',
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->patchJson("/api/admin/banners/{$banner->id}/status", ['is_active' => false])
            ->assertForbidden();
        $this->patchJson('/api/admin/banners/reorder', ['orders' => [$banner->id]])
            ->assertForbidden();
        $this->deleteJson("/api/admin/banners/{$banner->id}")->assertForbidden();

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'title' => 'Penerimaan Murid Baru']);
    }

    public function test_guest_cannot_access_admin_banner_endpoints(): void
    {
        $this->getJson('/api/admin/banners')->assertUnauthorized();
        $this->patchJson('/api/admin/banners/reorder', ['orders' => [1]])->assertUnauthorized();
    }

    public function test_create_rejects_dangerous_cta_url_scheme(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->admin());

        $this->post('/api/admin/banners', [
            'title'   => 'X',
            'cta_url' => 'javascript:alert(1)',
            'image'   => UploadedFile::fake()->image('x.png', 10, 10),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cta_url');
    }

    public function test_create_rejects_non_image_upload(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->admin());

        $this->post('/api/admin/banners', [
            'title' => 'X',
            'image' => UploadedFile::fake()->create('evil.php', 10, 'image/png'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_update_replaces_image_and_deletes_old_file(): void
    {
        $banner = $this->createBanner();
        $oldPath = $banner->image_path;

        Sanctum::actingAs($this->admin());
        $this->post("/api/admin/banners/{$banner->id}", [
            'title' => 'Judul Baru',
            'image' => UploadedFile::fake()->image('baru.jpg', 1024, 768),
        ], ['Accept' => 'application/json'])->assertOk();

        $banner->refresh();
        $this->assertSame('Judul Baru', $banner->title);
        $this->assertNotSame($oldPath, $banner->image_path);
        Storage::disk('private')->assertMissing($oldPath);
        Storage::disk('private')->assertExists($banner->image_path);
    }

    public function test_toggle_status_hides_banner_from_public(): void
    {
        $banner = $this->createBanner();
        $this->assertCount(1, $this->getJson('/api/banners')->json());

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/admin/banners/{$banner->id}/status", ['is_active' => false])
            ->assertOk();

        $this->assertCount(0, $this->getJson('/api/banners')->json());
    }

    public function test_reorder_persists_public_slide_order(): void
    {
        $a = $this->createBanner(['title' => 'A', 'sort_order' => '0']);
        $b = $this->createBanner(['title' => 'B', 'sort_order' => '1']);

        Sanctum::actingAs($this->admin());
        $this->patchJson('/api/admin/banners/reorder', ['orders' => [$b->id, $a->id]])
            ->assertOk();

        $titles = array_column($this->getJson('/api/banners')->json(), 'title');
        $this->assertSame(['B', 'A'], $titles);
    }

    public function test_destroy_removes_banner_and_its_file(): void
    {
        $banner = $this->createBanner();
        $path = $banner->image_path;

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/admin/banners/{$banner->id}")->assertOk();

        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
        Storage::disk('private')->assertMissing($path);
    }
}
