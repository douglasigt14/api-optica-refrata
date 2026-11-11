<?php

namespace Tests\Feature\Admin;

use App\Enums\LeadStage;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Prospector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminLeadTest extends TestCase
{
    use RefreshDatabase;

    /** Situação padrão da etapa (criada pela migration). */
    private function statusId(string $stage): int
    {
        return LeadStatus::defaultFor(LeadStage::from($stage))->id;
    }

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = User::factory()->team(UserRole::FieldAgent)->create(['name' => 'Ana Atendente']);
        Passport::actingAs($this->agent);
    }

    private function leadInVisit(string $start = '2026-11-22', ?string $end = '2026-11-24'): Lead
    {
        $visit = CityVisit::factory()->create(['visit_date' => $start, 'end_date' => $end]);

        return Lead::factory()->create(['city_id' => $visit->city_id, 'city_visit_id' => $visit->id]);
    }

    public function test_lists_leads_with_filters_and_search(): void
    {
        $city = City::factory()->create();
        $prospector = Prospector::factory()->create();
        $maria = Lead::factory()->for($prospector)->create(['city_id' => $city->id, 'name' => 'Maria Souza', 'phone_number' => '88999991234']);
        Lead::factory()->create(['name' => 'João Lima']);
        Lead::factory()->create(['name' => 'Apagada'])->delete();

        $this->getJson('/api/admin/leads')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'phone_number', 'status', 'appointment_date', 'city', 'visit', 'prospector' => ['id', 'name'], 'contacts_count']]]);

        foreach (["city_id={$city->id}", "prospector_id={$prospector->id}", 'search=souza', 'search=(88) 99999-1234', 'stage=new&search=maria'] as $query) {
            $this->getJson("/api/admin/leads?{$query}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $maria->id);
        }

        $this->getJson('/api/admin/leads?stage=scheduled')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_team_creates_a_lead_for_a_partner(): void
    {
        $visit = CityVisit::factory()->create(['visit_date' => today()->addDays(5)]);
        $prospector = Prospector::factory()->create(['name' => 'Carlos Parceiro']);
        $trusted = Prospector::factory()->create(['trusted_at' => now()]);
        $blocked = Prospector::factory()->create(['blocked_at' => now()]);
        $lead = fn (array $data = []) => [
            'prospector_id' => $prospector->id,
            'name' => 'João Pereira',
            'phone_number' => '(88) 98888-7777',
            'city_id' => $visit->city_id,
            'city_visit_id' => $visit->id,
            ...$data,
        ];

        $this->postJson('/api/admin/leads', $lead())
            ->assertCreated()
            ->assertJsonPath('data.name', 'João Pereira')
            ->assertJsonPath('data.phone_number', '88988887777')
            ->assertJsonPath('data.prospector.name', 'Carlos Parceiro')
            ->assertJsonPath('data.visit.id', $visit->id)
            ->assertJsonPath('data.status.stage', 'new');
        $this->assertDatabaseHas('leads', ['prospector_id' => $prospector->id, 'name' => 'João Pereira', 'stage' => 'new']);

        // Parceiro confiável: já nasce Agendada no 1º dia do atendimento, como no app.
        $this->postJson('/api/admin/leads', $lead(['prospector_id' => $trusted->id]))
            ->assertCreated()
            ->assertJsonPath('data.status.stage', 'scheduled')
            ->assertJsonPath('data.appointment_date', $visit->visit_date->toDateString());

        $this->postJson('/api/admin/leads', $lead(['prospector_id' => $blocked->id]))->assertJsonValidationErrors(['prospector_id']);
        $this->postJson('/api/admin/leads', $lead(['prospector_id' => null]))->assertJsonValidationErrors(['prospector_id' => 'Escolha o parceiro.']);
        $this->postJson('/api/admin/leads', $lead(['city_visit_id' => CityVisit::factory()->create()->id]))->assertJsonValidationErrors(['city_visit_id']);
    }

    public function test_schedules_exam_within_the_visit_period(): void
    {
        $lead = $this->leadInVisit();

        foreach (['2026-11-21', '2026-11-25'] as $outside) {
            $this->patchJson("/api/admin/leads/{$lead->id}/schedule", ['appointment_date' => $outside])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['appointment_date' => 'Escolha um dia dentro do período do atendimento.']);
        }

        $this->patchJson("/api/admin/leads/{$lead->id}/schedule", ['appointment_date' => '2026-11-23'])
            ->assertOk()
            ->assertJsonPath('data.appointment_date', '2026-11-23')
            ->assertJsonPath('data.stage', 'scheduled');

        // desmarcar volta para "Em contato"
        $this->patchJson("/api/admin/leads/{$lead->id}/schedule", ['appointment_date' => null])
            ->assertOk()
            ->assertJsonPath('data.appointment_date', null)
            ->assertJsonPath('data.stage', 'contacting');
    }

    public function test_one_day_visit_only_accepts_that_day(): void
    {
        $lead = $this->leadInVisit('2026-11-22', null);

        $this->patchJson("/api/admin/leads/{$lead->id}/schedule", ['appointment_date' => '2026-11-23'])->assertUnprocessable();
        $this->patchJson("/api/admin/leads/{$lead->id}/schedule", ['appointment_date' => '2026-11-22'])->assertOk();
    }

    public function test_lead_without_visit_cannot_be_scheduled(): void
    {
        $lead = Lead::factory()->create(['city_visit_id' => null]);

        $this->patchJson("/api/admin/leads/{$lead->id}/schedule", ['appointment_date' => '2026-11-22'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_date']);
    }

    public function test_status_rules(): void
    {
        $lead = $this->leadInVisit();

        $this->patchJson("/api/admin/leads/{$lead->id}/status", ['lead_status_id' => $this->statusId('scheduled')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead_status_id' => 'Para agendar, escolha o dia do exame.']);

        $this->patchJson("/api/admin/leads/{$lead->id}/status", ['lead_status_id' => $this->statusId('attended')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead_status_id' => 'Marque o dia do exame antes.']);

        $lead->update(['stage' => LeadStage::Scheduled, 'appointment_date' => '2026-11-23']);

        $this->patchJson("/api/admin/leads/{$lead->id}/status", ['lead_status_id' => $this->statusId('attended')])
            ->assertOk()
            ->assertJsonPath('data.stage', 'attended')
            ->assertJsonPath('data.appointment_date', '2026-11-23');

        // descartar desmarca o exame
        $this->patchJson("/api/admin/leads/{$lead->id}/status", ['lead_status_id' => $this->statusId('discarded')])
            ->assertOk()
            ->assertJsonPath('data.stage', 'discarded')
            ->assertJsonPath('data.appointment_date', null);
    }

    public function test_registers_contacts_in_the_history(): void
    {
        $lead = $this->leadInVisit();

        $this->postJson("/api/admin/leads/{$lead->id}/contacts", ['channel' => 'whatsapp', 'result' => 'no_answer'])
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Ana Atendente');
        $this->postJson("/api/admin/leads/{$lead->id}/contacts", ['channel' => 'phone_call', 'result' => 'reached', 'notes' => 'Prefere sábado'])
            ->assertCreated();

        $this->assertSame(LeadStage::Contacting, $lead->refresh()->stage);

        $this->getJson("/api/admin/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.contacts')
            ->assertJsonPath('data.contacts.0.notes', 'Prefere sábado')
            ->assertJsonPath('data.contacts.0.channel', 'phone_call');

        $this->postJson("/api/admin/leads/{$lead->id}/contacts", ['channel' => 'pombo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel', 'result']);
    }

    public function test_partner_can_only_delete_new_leads(): void
    {
        $partner = User::factory()->create();
        $new = Lead::factory()->for($partner->prospector)->create();
        $scheduled = Lead::factory()->for($partner->prospector)->create(['stage' => LeadStage::Scheduled, 'appointment_date' => now()]);
        Passport::actingAs($partner);

        $this->getJson('/api/leads')->assertOk()->assertJsonPath('data.0.stage', 'scheduled');

        $this->deleteJson("/api/leads/{$scheduled->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead']);
        $this->assertNotSoftDeleted($scheduled);

        $this->deleteJson("/api/leads/{$new->id}")->assertNoContent();
    }

    public function test_switches_status_within_the_stage_and_keeps_it_on_automatic_moves(): void
    {
        $lead = $this->leadInVisit();
        $noAnswer = LeadStatus::create(['name' => 'Sem resposta', 'partner_name' => 'Em contato', 'stage' => LeadStage::Contacting, 'color' => 'orange']);
        $rescheduled = LeadStatus::create(['name' => 'Reagendada', 'partner_name' => 'Exame marcado', 'stage' => LeadStage::Scheduled, 'color' => 'teal']);

        $this->patchJson("/api/admin/leads/{$lead->id}/status", ['lead_status_id' => $noAnswer->id])
            ->assertOk()
            ->assertJsonPath('data.stage', 'contacting')
            ->assertJsonPath('data.status.name', 'Sem resposta');

        // 1º contato não mexe: já está em Em contato
        $this->postJson("/api/admin/leads/{$lead->id}/contacts", ['channel' => 'whatsapp', 'result' => 'no_answer'])->assertCreated();
        $this->assertSame($noAnswer->id, $lead->refresh()->lead_status_id);

        // situação de Agendada sem dia marcado → recusa
        $this->patchJson("/api/admin/leads/{$lead->id}/status", ['lead_status_id' => $rescheduled->id])
            ->assertJsonValidationErrors(['lead_status_id' => 'Para agendar, escolha o dia do exame.']);

        // marcar o dia → padrão de Agendada; depois troca para outra de Agendada
        $this->patchJson("/api/admin/leads/{$lead->id}/schedule", ['appointment_date' => '2026-11-23'])
            ->assertJsonPath('data.status.id', $this->statusId('scheduled'));
        $this->patchJson("/api/admin/leads/{$lead->id}/status", ['lead_status_id' => $rescheduled->id])
            ->assertOk()
            ->assertJsonPath('data.status.name', 'Reagendada')
            ->assertJsonPath('data.appointment_date', '2026-11-23');

        // situação desativada não pode ser escolhida
        $noAnswer->update(['active' => false]);
        $this->patchJson("/api/admin/leads/{$lead->id}/status", ['lead_status_id' => $noAnswer->id])
            ->assertJsonValidationErrors(['lead_status_id']);

        $this->getJson('/api/admin/leads?lead_status_id='.$rescheduled->id)->assertJsonPath('meta.total', 1);
    }

    public function test_keeps_the_status_history_with_who_changed(): void
    {
        $partner = User::factory()->create();
        $visit = CityVisit::factory()->create();
        Passport::actingAs($partner);
        $id = $this->postJson('/api/leads', ['name' => 'João', 'phone_number' => '88988887777', 'city_id' => $visit->city_id, 'city_visit_id' => $visit->id])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'new')
            ->assertJsonPath('data.status.name', 'Recebida') // nome que o parceiro vê
            ->json('data.id');

        Passport::actingAs($this->agent);
        $this->patchJson("/api/admin/leads/{$id}/status", ['lead_status_id' => $this->statusId('discarded')])->assertOk();

        $this->getJson("/api/admin/leads/{$id}")
            ->assertJsonCount(2, 'data.status_changes')
            ->assertJsonPath('data.status_changes.0.from.name', 'Nova')
            ->assertJsonPath('data.status_changes.0.to.name', 'Descartada')
            ->assertJsonPath('data.status_changes.0.user.name', 'Ana Atendente')
            ->assertJsonPath('data.status_changes.1.from', null)
            ->assertJsonPath('data.status_changes.1.to.name', 'Nova')
            ->assertJsonPath('data.status_changes.1.user.name', $partner->prospector->name)
            ->assertJsonPath('data.status_changes.1.user.is_partner', true);
    }
}
