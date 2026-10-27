<?php

namespace Tests\Feature\Admin;

use App\Enums\LeadStage;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Lead;
use App\Models\Prospector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminProspectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Os testes que entram como parceiro geram token.
        $this->artisan('passport:client', ['--personal' => true, '--name' => 'Testes', '--provider' => 'users', '--no-interaction' => true]);
        Passport::actingAs(User::factory()->team()->create());
    }

    public function test_lists_prospectors_with_filters_and_counts(): void
    {
        $iguatu = City::factory()->create();
        $maria = Prospector::factory()->create(['name' => 'Maria Souza', 'city_id' => $iguatu->id, 'phone_number' => '88999990001']);
        Prospector::factory()->create(['name' => 'Ana Lima', 'city_id' => $iguatu->id, 'blocked_at' => now()]);
        Prospector::factory()->create(['name' => 'Bruno']); // outra cidade
        Lead::factory()->create(['prospector_id' => $maria->id]);
        Lead::factory()->create(['prospector_id' => $maria->id, 'stage' => LeadStage::Attended, 'appointment_date' => '2026-10-15']);

        $this->getJson("/api/admin/prospectors?city_id={$iguatu->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Ana Lima')
            ->assertJsonPath('data.0.blocked', true)
            ->assertJsonPath('data.1.leads_count', 2)
            ->assertJsonPath('data.1.attended_count', 1)
            ->assertJsonPath('data.1.pix_key', $maria->pix_key);

        $this->getJson('/api/admin/prospectors?blocked=0&search=(88) 99999-0001')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $maria->id);

        $this->getJson('/api/admin/prospectors/options')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Ana Lima');
    }

    public function test_shows_a_prospector_with_leads_by_stage(): void
    {
        $prospector = Prospector::factory()->create();
        Lead::factory()->count(2)->create(['prospector_id' => $prospector->id]);
        Lead::factory()->create(['prospector_id' => $prospector->id, 'stage' => LeadStage::Contacting]);

        $this->getJson("/api/admin/prospectors/{$prospector->id}")
            ->assertOk()
            ->assertJsonPath('data.leads_count', 3)
            ->assertJsonPath('data.leads_by_stage.new', 2)
            ->assertJsonPath('data.leads_by_stage.contacting', 1)
            ->assertJsonPath('data.leads_by_stage.attended', 0);
    }

    public function test_admin_creates_a_prospector_who_can_log_in(): void
    {
        $city = City::factory()->create();

        $this->postJson('/api/admin/prospectors', [
            'name' => 'Carla Dias',
            'birth_date' => '1990-05-10',
            'phone_number' => '(88) 99888-7766',
            'city_id' => $city->id,
            'pix_key' => 'carla@exemplo.com',
            'instagram_handle' => '@carla.dias',
            'password' => 'senha12345',
        ])
            ->assertCreated()
            ->assertJsonPath('data.phone_number', '88998887766')
            ->assertJsonPath('data.instagram_handle', 'carla.dias')
            ->assertJsonPath('data.blocked', false);

        $this->postJson('/api/login', ['phone_number' => '88998887766', 'password' => 'senha12345'])->assertOk();
    }

    public function test_validation_and_duplicate_phone(): void
    {
        $existing = Prospector::factory()->create();
        $inactive = City::factory()->inactive()->create();

        $this->postJson('/api/admin/prospectors', [
            'name' => '',
            'birth_date' => now()->subYears(10)->toDateString(),
            'phone_number' => $existing->phone_number,
            'city_id' => $inactive->id,
            'password' => '123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'birth_date' => 'O parceiro precisa ter 18 anos ou mais.',
                'phone_number' => 'Este telefone já está cadastrado.',
                'city_id' => 'Escolha uma cidade ativa.',
                'pix_key',
                'password',
            ]);
    }

    public function test_admin_edits_and_phone_change_updates_the_login(): void
    {
        $user = User::factory()->create();
        $prospector = $user->prospector;

        $this->patchJson("/api/admin/prospectors/{$prospector->id}", ['name' => 'Novo Nome', 'phone_number' => $prospector->phone_number])
            ->assertOk()
            ->assertJsonPath('data.name', 'Novo Nome');

        $this->patchJson("/api/admin/prospectors/{$prospector->id}", ['phone_number' => '88977776666'])
            ->assertOk()
            ->assertJsonPath('data.phone_number', '88977776666');
        $this->assertSame('88977776666', $user->fresh()->phone_number);

        $this->patchJson("/api/admin/prospectors/{$prospector->id}", ['password' => 'qualquer123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_blocking_logs_out_and_prevents_login_and_new_leads(): void
    {
        $user = User::factory()->create();
        $prospector = $user->prospector;
        $user->createToken('app');

        $this->postJson("/api/admin/prospectors/{$prospector->id}/block")
            ->assertOk()
            ->assertJsonPath('data.blocked', true);
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/login', ['phone_number' => $prospector->phone_number, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number' => 'Seu cadastro está bloqueado. Fale com a equipe da ótica.']);

        Passport::actingAs($user->fresh());
        $this->postJson('/api/leads', ['name' => 'X', 'phone_number' => '88911112222'])->assertForbidden();

        Passport::actingAs(User::factory()->team()->create());
        $this->deleteJson("/api/admin/prospectors/{$prospector->id}/block")
            ->assertOk()
            ->assertJsonPath('data.blocked', false);
        $this->postJson('/api/login', ['phone_number' => $prospector->phone_number, 'password' => 'password'])->assertOk();
    }

    public function test_admin_sets_a_new_password(): void
    {
        $user = User::factory()->create();
        $user->createToken('app');

        $this->putJson("/api/admin/prospectors/{$user->prospector_id}/password", ['password' => 'curta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'A senha precisa ter pelo menos 8 caracteres.']);

        $this->putJson("/api/admin/prospectors/{$user->prospector_id}/password", ['password' => 'novasenha123'])->assertNoContent();

        $this->assertTrue(Hash::check('novasenha123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_field_agent_only_reads(): void
    {
        $prospector = Prospector::factory()->create();
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        $this->getJson('/api/admin/prospectors')->assertOk();
        $this->getJson("/api/admin/prospectors/{$prospector->id}")->assertOk();
        $this->patchJson("/api/admin/prospectors/{$prospector->id}", ['name' => 'X'])->assertForbidden();
        $this->postJson("/api/admin/prospectors/{$prospector->id}/block")->assertForbidden();
        $this->putJson("/api/admin/prospectors/{$prospector->id}/password", ['password' => 'novasenha123'])->assertForbidden();
        $this->postJson('/api/admin/prospectors', [])->assertForbidden();
    }

    public function test_field_agent_marks_a_prospector_as_trusted_and_new_leads_get_scheduled(): void
    {
        $prospector = Prospector::factory()->create();
        $visit = CityVisit::factory()->create(['visit_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString()]);
        $started = CityVisit::factory()->create(['visit_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString()]);
        $past = CityVisit::factory()->create(['visit_date' => now()->subDays(10)->toDateString()]);
        $new = Lead::factory()->create(['prospector_id' => $prospector->id, 'city_visit_id' => $visit->id]);
        $newStarted = Lead::factory()->create(['prospector_id' => $prospector->id, 'city_visit_id' => $started->id]);
        $newPast = Lead::factory()->create(['prospector_id' => $prospector->id, 'city_visit_id' => $past->id]);
        $contacting = Lead::factory()->create(['prospector_id' => $prospector->id, 'city_visit_id' => $visit->id, 'stage' => LeadStage::Contacting]);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        $this->postJson("/api/admin/prospectors/{$prospector->id}/trust")
            ->assertOk()
            ->assertJsonPath('data.trusted', true)
            ->assertJsonPath('data.leads_by_stage.scheduled', 2);

        $this->assertSame(LeadStage::Scheduled, $new->fresh()->stage);
        $this->assertSame($visit->visit_date->toDateString(), $new->fresh()->appointment_date->toDateString());
        $this->assertSame(today()->toDateString(), $newStarted->fresh()->appointment_date->toDateString());
        $this->assertSame(LeadStage::New, $newPast->fresh()->stage);
        $this->assertSame(LeadStage::Contacting, $contacting->fresh()->stage);

        $this->getJson('/api/admin/prospectors?trusted=1')->assertJsonPath('meta.total', 1);

        $this->deleteJson("/api/admin/prospectors/{$prospector->id}/trust")
            ->assertOk()
            ->assertJsonPath('data.trusted', false);
        $this->assertSame(LeadStage::Scheduled, $new->fresh()->stage);
    }

    public function test_trusted_prospector_leads_are_born_scheduled(): void
    {
        $user = User::factory()->create();
        $visit = CityVisit::factory()->create(['visit_date' => now()->addDays(3)->toDateString()]);
        $lead = ['name' => 'João Pereira', 'phone_number' => '88988887777', 'city_id' => $visit->city_id, 'city_visit_id' => $visit->id];
        Passport::actingAs($user);

        $this->postJson('/api/leads', $lead)->assertCreated()->assertJsonPath('data.stage', 'new');

        $user->prospector->update(['trusted_at' => now()]);

        $this->postJson('/api/leads', $lead)
            ->assertCreated()
            ->assertJsonPath('data.stage', 'scheduled')
            ->assertJsonPath('data.appointment_date', $visit->visit_date->toDateString());
    }

    public function test_admin_sets_trusted_in_the_form(): void
    {
        $city = City::factory()->create();

        $id = $this->postJson('/api/admin/prospectors', [
            'name' => 'Maria Souza',
            'birth_date' => '1995-04-12',
            'phone_number' => '(88) 99999-1234',
            'city_id' => $city->id,
            'pix_key' => 'maria@exemplo.com',
            'password' => 'senha12345',
            'trusted' => true,
        ])->assertCreated()->assertJsonPath('data.trusted', true)->json('data.id');

        $this->patchJson("/api/admin/prospectors/{$id}", ['trusted' => false])->assertJsonPath('data.trusted', false);
        $this->patchJson("/api/admin/prospectors/{$id}", ['name' => 'Maria S.'])->assertJsonPath('data.trusted', false);
    }
}
