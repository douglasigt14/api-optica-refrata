<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminTeamUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->team()->create(['name' => 'Admin']);
        Passport::actingAs($this->admin);
    }

    public function test_lists_only_team_members(): void
    {
        User::factory()->team(UserRole::FieldAgent)->create();
        User::factory()->create(); // parceiro

        $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_creates_and_updates_a_team_member(): void
    {
        $id = $this->postJson('/api/admin/users', [
            'name' => 'Bia',
            'email' => 'Bia@Refrata.com',
            'role' => 'field_agent',
            'password' => 'segredo123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'bia@refrata.com')
            ->json('data.id');

        $this->patchJson("/api/admin/users/{$id}", ['role' => 'factory', 'password' => ''])
            ->assertOk()
            ->assertJsonPath('data.role', 'factory');
        $this->assertTrue(Hash::check('segredo123', User::find($id)->password));

        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'bia@refrata.com', 'role' => 'chefe', 'password' => '123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Já existe um usuário com este e-mail.', 'role', 'password']);
    }

    public function test_cannot_demote_or_remove_self_nor_touch_partners(): void
    {
        $this->patchJson("/api/admin/users/{$this->admin->id}", ['role' => 'factory'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
        $this->deleteJson("/api/admin/users/{$this->admin->id}")->assertUnprocessable();

        $partner = User::factory()->create();
        $this->patchJson("/api/admin/users/{$partner->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/admin/users/{$partner->id}")->assertNotFound();

        $member = User::factory()->team(UserRole::FieldAgent)->create();
        $this->deleteJson("/api/admin/users/{$member->id}")->assertNoContent();
        $this->assertModelMissing($member);
    }
}
