<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Prospector;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\DefaultLeadStatuses;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Ótica por empresa (passo 2 do roteiro da plataforma): a ótica A não lista, não vê, não altera nada da B; parceiros
 * entram pelo convite da ótica; cidades atendidas, situações e equipe são de cada ótica.
 */
class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PassportClientSeeder::class);
        $this->other = Company::factory()->create(['name' => 'Ótica Bem Estar', 'invite_code' => 'BEMESTAR']);
        DefaultLeadStatuses::createFor($this->other);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOther(callable $callback): mixed
    {
        return app(CurrentCompany::class)->within($this->other, $callback);
    }

    public function test_team_does_not_see_or_change_data_of_another_optica(): void
    {
        Passport::actingAs(User::factory()->team()->create());
        $mine = Lead::factory()->create(['name' => 'Indicação minha']);
        $theirs = $this->inOther(fn () => Lead::factory()->create(['name' => 'Indicação deles']));
        $theirPartner = $this->inOther(fn () => Prospector::factory()->create());
        $theirVisit = $this->inOther(fn () => CityVisit::factory()->create());
        $theirStatus = $this->inOther(fn () => LeadStatus::query()->first());

        $this->getJson('/api/admin/leads')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/admin/leads/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/admin/leads/{$mine->id}/status", ['lead_status_id' => $theirStatus->id])->assertUnprocessable();
        $this->getJson("/api/admin/prospectors/{$theirPartner->id}")->assertNotFound();
        $this->postJson("/api/admin/prospectors/{$theirPartner->id}/block")->assertNotFound();
        $this->getJson("/api/admin/city-visits/{$theirVisit->id}")->assertNotFound();
        $this->getJson('/api/admin/lead-statuses')->assertOk()->assertJsonCount(count(DefaultLeadStatuses::LIST), 'data');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.leads.total', 1);
        $this->assertNull($theirPartner->fresh()->blocked_at);
    }

    public function test_partner_sees_only_their_optica(): void
    {
        $partner = User::factory()->create();
        $theirCity = $this->inOther(fn () => City::factory()->create());
        Passport::actingAs($partner);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.prospector.company.name', 'Otica Exclusive');
        // A lista de cidades, com o login do parceiro, é a da ótica dele (mesmo com duas óticas na plataforma).
        City::factory()->create(['name' => 'Iguatu']);
        $this->getJson('/api/cities')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/cities/{$theirCity->id}/visits")->assertNotFound();
        $this->postJson('/api/leads', ['name' => 'Ana', 'phone_number' => '88999990000', 'city_id' => $theirCity->id, 'city_visit_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('city_id');
    }

    public function test_partner_registers_in_the_optica_of_the_invite(): void
    {
        $city = $this->inOther(fn () => City::factory()->create(['name' => 'Iguatu']));
        City::factory()->create(['name' => 'Aracati']);

        $this->getJson('/api/companies/bemestar')->assertOk()->assertJsonPath('data.name', 'Ótica Bem Estar')
            ->assertJsonCount(1, 'data.cities')->assertJsonPath('data.cities.0.name', 'Iguatu');
        $this->getJson('/api/companies/NAOEXISTE')->assertNotFound();
        $this->getJson('/api/cities?invite_code=BEMESTAR')->assertOk()->assertJsonCount(1, 'data');
        // Com duas óticas, o código é obrigatório.
        $this->getJson('/api/cities')->assertNotFound();

        $data = [
            'name' => 'Maria Souza', 'birth_date' => '1995-04-12', 'phone_number' => '88999991234', 'city_id' => $city->id,
            'pix_key' => 'maria@exemplo.com', 'password' => 'senha-segura', 'password_confirmation' => 'senha-segura',
        ];
        $this->postJson('/api/register', $data)->assertUnprocessable()->assertJsonValidationErrors(['invite_code' => 'Informe o código da ótica.']);
        $this->postJson('/api/register', [...$data, 'invite_code' => 'ERRADO'])->assertUnprocessable()->assertJsonValidationErrors('invite_code');
        // Cidade de outra ótica não vale.
        $this->postJson('/api/register', [...$data, 'invite_code' => 'EXCLUSIVE'])->assertUnprocessable()->assertJsonValidationErrors('city_id');
        $this->postJson('/api/register', [...$data, 'invite_code' => 'bemestar'])->assertCreated()
            ->assertJsonPath('user.prospector.company.name', 'Ótica Bem Estar');

        $this->assertSame($this->other->id, Prospector::withoutGlobalScopes()->firstWhere('phone_number', '88999991234')->company_id);
    }

    public function test_inactive_optica_or_blocked_group_locks_everyone_out(): void
    {
        $partner = User::factory()->create();
        $admin = User::factory()->team()->create();
        $this->company->group->update(['blocked_at' => now()]);

        $this->postJson('/api/login', ['phone_number' => $partner->phone_number, 'password' => 'password'])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number' => 'Acesso bloqueado. Fale com a equipe da ótica.']);
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertUnprocessable();
        Passport::actingAs($partner);
        $this->getJson('/api/leads')->assertForbidden();
        $this->getJson('/api/companies/EXCLUSIVE')->assertNotFound();
    }

    public function test_team_member_chooses_the_optica(): void
    {
        $user = User::factory()->team(UserRole::FieldAgent)->create();
        $user->companies()->attach($this->other->id, ['role' => UserRole::Admin->value]);
        Passport::actingAs($user->fresh());

        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.company_id', null)->assertJsonCount(2, 'data.companies');
        $this->getJson('/api/admin/leads')->assertStatus(409);
        $this->getJson('/api/admin/me', ['X-Company' => (string) $this->other->id])->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.companies.0.invite_code', null)->assertJsonPath('data.companies.1.invite_code', 'BEMESTAR');
        // Atendente na Exclusive, admin na Bem Estar: usuários só na Bem Estar.
        $this->getJson('/api/admin/users', ['X-Company' => (string) $this->company->id])->assertForbidden();
        $this->getJson('/api/admin/users', ['X-Company' => (string) $this->other->id])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/leads', ['X-Company' => (string) Company::factory()->create()->id])->assertForbidden();
    }

    public function test_admin_chooses_the_cities_the_optica_serves(): void
    {
        Passport::actingAs(User::factory()->team()->create());
        $iguatu = City::factory()->inactive()->create(['name' => 'Iguatu']);
        $served = City::factory()->create(['name' => 'Aracati']);
        CityVisit::factory()->create(['city_id' => $served->id]);

        $this->getJson('/api/admin/cities/search?search=igua')->assertOk()->assertJsonPath('data.0.served', false);
        $this->putJson("/api/admin/cities/{$iguatu->id}")->assertOk()->assertJsonPath('data.served', true);
        $this->getJson('/api/admin/cities')->assertOk()->assertJsonCount(2, 'data');
        $this->assertFalse($this->inOther(fn () => City::servedBy()->whereKey($iguatu->id)->exists()));
        // Com atendimento aberto, não tira.
        $this->deleteJson("/api/admin/cities/{$served->id}")->assertUnprocessable();
        $this->deleteJson("/api/admin/cities/{$iguatu->id}")->assertNoContent();
        $this->getJson('/api/admin/cities')->assertOk()->assertJsonCount(1, 'data');

        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->putJson("/api/admin/cities/{$iguatu->id}")->assertForbidden();
    }
}
