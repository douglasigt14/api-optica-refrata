<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PassportClientSeeder::class);
    }

    public function test_team_member_logs_in_with_email_and_password(): void
    {
        $user = User::factory()->team(UserRole::FieldAgent)->create(['email' => 'ana@refrata.com']);

        $this->postJson('/api/admin/login', ['email' => ' ANA@refrata.com ', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'expires_at', 'user' => ['id', 'name', 'email', 'role', 'role_label']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'field_agent')
            ->assertJsonPath('user.role_label', 'Atendente de campo');
    }

    public function test_wrong_password_or_partner_cannot_log_in(): void
    {
        User::factory()->team()->create(['email' => 'ana@refrata.com']);
        $partner = User::factory()->create(['email' => 'parceiro@exemplo.com']);

        $this->postJson('/api/admin/login', ['email' => 'ana@refrata.com', 'password' => 'errada123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'E-mail ou senha incorretos.']);

        $this->postJson('/api/admin/login', ['email' => $partner->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'E-mail ou senha incorretos.']);
    }

    public function test_partner_token_cannot_use_admin_routes(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson('/api/admin/me')->assertForbidden();
        $this->getJson('/api/admin/leads')->assertForbidden();
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->getJson('/api/admin/city-visits')->assertUnauthorized();
    }

    public function test_roles_limit_what_each_member_can_do(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::Factory)->create());
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.role', 'factory');
        $this->getJson('/api/admin/leads')->assertForbidden();

        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->getJson('/api/admin/city-visits')->assertOk();
        $this->getJson('/api/admin/leads')->assertOk();
        $this->postJson('/api/admin/city-visits', [])->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('admin:create', ['--name' => 'Douglas', '--email' => 'Douglas@Exemplo.com', '--password' => 'segredo123'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'douglas@exemplo.com', 'role' => 'admin', 'phone_number' => null]);

        $this->artisan('admin:create', ['--name' => 'Outro', '--email' => 'douglas@exemplo.com', '--password' => 'segredo123'])
            ->assertFailed();
    }
}
