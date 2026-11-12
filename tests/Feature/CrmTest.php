<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\CrmEntry;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * CRM da Ótica, fase 2 (12/11/2026): clientes por atendimento, contatos, etapas (o resultado puxa a etapa; ficha no
 * atendimento leva a "Foi"/"Comprou de novo"), "Não foi" em lote, resultados, ranking e retornos.
 */
class CrmTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private CityVisit $visit;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = City::factory()->create(['name' => 'Assaré']);
        $this->visit = CityVisit::factory()->create(['city_id' => $this->city->id, 'visit_date' => today()->addDays(4), 'end_date' => today()->addDays(5)]);
        $this->agent = User::factory()->team(UserRole::FieldAgent)->create(['name' => 'Romana']);
        Passport::actingAs($this->agent);
    }

    private function client(string $name, ?string $lastPurchase = null, string $phone = '88994669561'): Client
    {
        return Client::create(['name' => $name, 'city_id' => $this->city->id, 'phone_number' => $phone, 'last_purchase_date' => $lastPurchase]);
    }

    private function url(string $path = ''): string
    {
        return "/api/admin/crm/visits/{$this->visit->id}{$path}";
    }

    public function test_lists_the_city_clients_with_cards_and_filters(): void
    {
        $old = $this->client('Ana Antiga', today()->subYears(3)->toDateString());
        $this->client('Bia Recente', today()->subMonths(2)->toDateString());
        $this->client('Caio Sem Data');
        Client::create(['name' => 'De Outra Cidade', 'city_id' => City::factory()->create()->id]);

        $this->getJson('/api/admin/crm/visits')->assertOk()
            ->assertJsonPath('data.0.id', $this->visit->id)->assertJsonPath('data.0.clients_count', 3);
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.cards.clients', 3)
            ->assertJsonPath('data.cards.over_one_year', 1)
            ->assertJsonPath('data.cards.under_one_year', 1)
            ->assertJsonPath('data.cards.to_contact', 3);

        $list = $this->getJson($this->url('/clients'))->assertOk()->assertJsonPath('meta.total', 3);
        // Sigilo: sem o número para o atendente.
        $this->assertNull($list->json('data.0.phone_number'));
        $this->assertSame('(88) 9••••-••61', $list->json('data.0.phone_masked'));
        $this->assertSame('to_contact', $list->json('data.0.stage'));
        $this->getJson($this->url('/clients?period=mais_2a'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $old->id);
        $this->getJson($this->url('/clients?period=ate_6m'))->assertJsonPath('data.0.name', 'Bia Recente');
        $this->getJson($this->url('/clients?search=caio'))->assertJsonPath('meta.total', 1);
        $this->getJson($this->url('/clients?stage=to_contact'))->assertJsonPath('meta.total', 3);
    }

    public function test_contact_result_moves_the_stage(): void
    {
        $ana = $this->client('Ana');
        $base = ['channel' => 'whatsapp'];

        $this->postJson($this->url("/clients/{$ana->id}/contacts"), [...$base, 'result' => 'call_back'])->assertJsonValidationErrors(['follow_up_at']);
        $this->postJson($this->url("/clients/{$ana->id}/contacts"), [...$base, 'result' => 'call_back', 'follow_up_at' => today()->toDateString(), 'notes' => 'Pediu para ligar à tarde'])
            ->assertCreated()->assertJsonPath('data.stage', 'talking')->assertJsonPath('data.follow_up_at', today()->toDateString())
            ->assertJsonPath('data.last_user.name', 'Romana');
        $this->getJson('/api/admin/crm/follow-ups')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ana->id);

        // Marcou: o dia tem de estar dentro do atendimento.
        $this->postJson($this->url("/clients/{$ana->id}/contacts"), [...$base, 'result' => 'booked', 'appointment_date' => today()->toDateString()])
            ->assertJsonValidationErrors(['appointment_date' => 'dentro do atendimento']);
        $this->postJson($this->url("/clients/{$ana->id}/contacts"), [...$base, 'result' => 'booked', 'appointment_date' => $this->visit->visit_date->toDateString()])
            ->assertCreated()->assertJsonPath('data.stage', 'booked')->assertJsonPath('data.follow_up_at', null)
            ->assertJsonPath('data.timeline.0.kind', 'stage')->assertJsonPath('data.timeline.1.result_label', 'Marcou');
        $this->getJson('/api/admin/crm/follow-ups')->assertJsonCount(0, 'data');

        $this->getJson($this->url())->assertJsonPath('data.cards.contacted', 1)->assertJsonPath('data.cards.booked', 1)
            ->assertJsonPath('data.ranking.0.user.name', 'Romana')->assertJsonPath('data.ranking.0.contacts', 2)->assertJsonPath('data.ranking.0.bookings', 1);

        $bia = $this->client('Bia');
        $this->postJson($this->url("/clients/{$bia->id}/contacts"), [...$base, 'result' => 'wrong_number'])->assertJsonPath('data.stage', 'not_interested');
    }

    public function test_sale_in_the_visit_moves_to_went_and_bought(): void
    {
        $ana = $this->client('Ana');
        $this->postJson($this->url("/clients/{$ana->id}/contacts"), ['channel' => 'whatsapp_call', 'result' => 'booked', 'appointment_date' => $this->visit->visit_date->toDateString()])->assertCreated();

        $sale = Sale::create(['city_visit_id' => $this->visit->id, 'client_id' => $ana->id, 'expected_delivery_date' => today()->addDays(10)]);
        $this->assertSame('attended', CrmEntry::first()->stage->value);
        $sale->update(['launched_at' => now()]);
        $this->assertSame('bought', CrmEntry::first()->stage->value);
        $this->getJson($this->url())->assertJsonPath('data.results.went', 1)->assertJsonPath('data.results.bought', 1)->assertJsonPath('data.results.conversion_rate', 1);

        // Quem o CRM não trabalhou não entra na conta.
        $walkIn = $this->client('Sem contato');
        Sale::create(['city_visit_id' => $this->visit->id, 'client_id' => $walkIn->id, 'expected_delivery_date' => today()->addDays(10)]);
        $this->assertSame(1, CrmEntry::count());
    }

    public function test_manual_stage_and_no_shows(): void
    {
        $ana = $this->client('Ana');
        $bia = $this->client('Bia');
        $this->putJson($this->url("/clients/{$ana->id}/stage"), ['stage' => 'booked', 'appointment_date' => $this->visit->visit_date->toDateString()])
            ->assertOk()->assertJsonPath('data.stage', 'booked');
        $this->putJson($this->url("/clients/{$bia->id}/stage"), ['stage' => 'booked'])->assertOk();

        $this->postJson($this->url('/no-shows'))->assertOk()->assertJsonPath('data.count', 2);
        $this->getJson($this->url())->assertJsonPath('data.results.no_show', 2)->assertJsonPath('data.results.attendance_rate', 0);
        $this->getJson($this->url("/clients/{$ana->id}"))->assertJsonPath('data.stage', 'no_show')->assertJsonPath('data.timeline.0.kind', 'auto');
    }
}
