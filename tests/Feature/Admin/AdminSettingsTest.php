<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** Configurações da ótica (12/11/2026): a logo das impressões. */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PassportClientSeeder::class);
    }

    public function test_admin_uploads_replaces_and_removes_the_print_logo(): void
    {
        Storage::fake('local');
        Passport::actingAs(User::factory()->team()->create());

        $this->getJson('/api/admin/settings')->assertOk()->assertJsonPath('data.print_logo_url', null);
        $this->post('/api/admin/settings/print-logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['logo']);

        $url = $this->post('/api/admin/settings/print-logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.print_logo_url');
        $this->assertNotNull($url);
        $first = app(CurrentCompany::class)->require()->fresh()->print_logo_path;
        Storage::disk('local')->assertExists($first);
        // O link assinado abre sem token; sem a assinatura, não.
        $this->get($url)->assertOk();
        $this->get(strtok($url, '?'))->assertForbidden();

        // A nova substitui a anterior.
        $this->post('/api/admin/settings/print-logo', ['logo' => UploadedFile::fake()->image('nova.jpg', 300, 100)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($first);

        $this->deleteJson('/api/admin/settings/print-logo')->assertOk()->assertJsonPath('data.print_logo_url', null);
        $this->assertNull(app(CurrentCompany::class)->require()->fresh()->print_logo_path);
    }

    public function test_field_agent_reads_but_does_not_change_the_logo(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        $this->getJson('/api/admin/settings')->assertOk();
        $this->post('/api/admin/settings/print-logo', ['logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/admin/settings/print-logo')->assertForbidden();
    }
}
