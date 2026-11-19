<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\LabConnection;
use App\Models\LabOrder;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Dashboard de pedidos (19/11/2026; admin e atendente, sem R$) e a parte financeira (só o admin).
 */
class OrdersDashboardTest extends TestCase
{
    use RefreshDatabase;

    private LabConnection $lab;

    private City $city;

    private CityVisit $visit;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        // 20/11/2026 13:00 em Fortaleza (sexta-feira)
        $this->travelTo(Carbon::parse('2026-11-20 16:00:00', 'UTC'));
        $this->lab = LabConnection::create(['lab_console_id' => '01JD0000000000000000ROYALL', 'name' => 'Royal Lab']);
        $this->city = City::factory()->create(['name' => 'Assaré', 'state' => 'CE']);
        $this->visit = CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Assaré Novembro', 'visit_date' => '2026-11-16', 'end_date' => '2026-11-17']);
        $this->agent = User::factory()->team(UserRole::FieldAgent)->create(['name' => 'Romana']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $payload */
    private function order(Client $client, ?Sale $sale, array $payload = [], array $extra = []): LabOrder
    {
        return LabOrder::create([
            'lab_connection_id' => $this->lab->id,
            'client_id' => $client->id,
            'city_visit_id' => $sale?->city_visit_id ?? $this->visit->id,
            'sale_id' => $sale?->id,
            'price' => 300,
            'user_id' => $this->agent->id,
            'payload' => $payload + ['lens_type' => 'single_vision', 'frame_type' => 'full_rim_metal', 'treatment_ids' => [], 'eyes' => []],
            ...$extra,
        ]);
    }

    private function client(string $name, array $extra = []): Client
    {
        return Client::create(['name' => $name, 'city_id' => $this->city->id, ...$extra]);
    }

    /** Duas fichas no atendimento: a da mãe (2 óculos, entregue) e a do João (1 óculos, mais 1 cancelado). */
    private function seedSales(): void
    {
        $mother = $this->client('Luiza', ['birth_date' => '1980-01-10', 'referral_source' => 'instagram']);
        $child = $this->client('Pedro', ['birth_date' => '2016-10-02']);
        $joao = $this->client('João', ['age' => 65, 'referral_source' => 'partner']);

        $first = Sale::create(['city_visit_id' => $this->visit->id, 'client_id' => $mother->id, 'expected_delivery_date' => '2026-11-25', 'user_id' => $this->agent->id, 'launched_at' => now(), 'delivered_at' => now()]);
        $this->travelTo(Carbon::parse('2026-11-16 15:00:00', 'UTC')); // segunda
        $this->order($mother, $first, ['frame_color' => ' preto ', 'treatment_ids' => [7, 2], 'eyes' => [['sphere' => -1.5, 'cylinder' => -0.5, 'addition' => null]]], ['sent_at' => now(), 'status' => 'delivered', 'status_label' => 'Entregue à Ótica']);
        $this->order($child, $first, ['lens_type' => 'multifocal', 'frame_color' => 'Preto', 'treatment_ids' => [7], 'eyes' => [['sphere' => 1, 'cylinder' => 0, 'addition' => 2]]], ['sent_at' => now(), 'status' => 'ready']);
        SalePayment::create(['sale_id' => $first->id, 'stage' => 'down', 'method' => 'pix', 'amount' => 200, 'paid_on' => '2026-11-16']);
        SalePayment::create(['sale_id' => $first->id, 'stage' => 'delivery', 'method' => 'cash', 'amount' => 400, 'paid_on' => '2026-11-20']);

        $second = Sale::create(['city_visit_id' => $this->visit->id, 'client_id' => $joao->id, 'expected_delivery_date' => '2026-11-25', 'user_id' => $this->agent->id, 'launched_at' => now()]);
        $this->travelTo(Carbon::parse('2026-11-17 15:00:00', 'UTC')); // terça
        $this->order($joao, $second, ['lens_type' => 'bifocal'], ['sent_at' => now(), 'status' => 'surfacing']);
        $this->order($joao, $second, [], ['sent_at' => now(), 'status' => 'canceled']);
        SalePayment::create(['sale_id' => $second->id, 'stage' => 'down', 'method' => 'card', 'amount' => 100, 'installments' => 2, 'paid_on' => '2026-11-17']);
        // ficha em rascunho, ainda sem envio
        $draft = Sale::create(['city_visit_id' => $this->visit->id, 'client_id' => $joao->id, 'expected_delivery_date' => '2026-11-25', 'user_id' => $this->agent->id]);
        $this->order($joao, $draft, ['lens_type' => 'bifocal']);
        $this->travelTo(Carbon::parse('2026-11-20 16:00:00', 'UTC'));
    }

    public function test_orders_dashboard_counts_glasses_without_money(): void
    {
        $this->seedSales();
        // fora do mês: não conta
        $this->travelTo(Carbon::parse('2026-10-10 15:00:00', 'UTC'));
        $this->order($this->client('Antiga'), null);
        $this->travelTo(Carbon::parse('2026-11-20 16:00:00', 'UTC'));
        Passport::actingAs($this->agent);

        $response = $this->getJson('/api/admin/dashboard/orders')
            ->assertOk()
            ->assertJsonPath('data.period', ['from' => '2026-11-01', 'to' => '2026-11-30'])
            ->assertJsonPath('data.totals.glasses', 4)
            ->assertJsonPath('data.totals.sales', 3)
            ->assertJsonPath('data.totals.clients', 3)
            ->assertJsonPath('data.totals.delivered', 2)
            ->assertJsonPath('data.totals.in_lab', 1)
            ->assertJsonPath('data.totals.not_sent', 1)
            ->assertJsonPath('data.totals.ready', 0)
            ->assertJsonPath('data.totals.visits', 1)
            ->assertJsonPath('data.by_visit.0.visit.title', 'Assaré Novembro')
            ->assertJsonPath('data.by_visit.0.glasses', 4)
            ->assertJsonPath('data.by_visit.0.sales', 3)
            ->assertJsonPath('data.lens_types.0', ['value' => 'bifocal', 'total' => 2])
            ->assertJsonPath('data.prescription_types', ['myopia' => 1, 'hyperopia' => 1, 'astigmatism' => 1, 'presbyopia' => 1])
            ->assertJsonPath('data.treatments.0', ['lab_connection_id' => $this->lab->id, 'treatment_id' => 7, 'total' => 2])
            ->assertJsonPath('data.frame_colors.0', ['value' => 'Preto', 'total' => 2])
            ->assertJsonPath('data.weekdays.0', ['weekday' => 1, 'total' => 2])
            ->assertJsonPath('data.weekdays.1', ['weekday' => 2, 'total' => 2])
            ->assertJsonPath('data.ranking_users.0.user.name', 'Romana')
            ->assertJsonPath('data.ranking_users.0.glasses', 4)
            ->assertJsonPath('data.ranking_cities.0.city.name', 'Assaré')
            ->assertJsonPath('data.ranking_cities.0.clients', 3)
            ->assertJsonCount(4, 'data.latest_orders');

        // Pessoas que compraram: Luiza (instagram) e João (parceiro); faixas: Pedro 0-12, Luiza 40-49, João 60+.
        $sources = collect($response->json('data.referral_sources'))->pluck('total', 'value');
        $this->assertSame(['instagram' => 1, 'partner' => 1], $sources->sortKeys()->all());
        $ages = collect($response->json('data.age_ranges'))->pluck('total', 'value');
        $this->assertSame([1, 1, 1], [$ages['0-12'], $ages['40-49'], $ages['60+']]);
        // Nenhum valor em R$ sai daqui.
        $this->assertStringNotContainsString('price', $response->getContent());
        $this->assertStringNotContainsString('"total":"', $response->getContent());
    }

    public function test_filters_by_visit_ignoring_the_period(): void
    {
        $this->seedSales();
        $other = CityVisit::factory()->create(['visit_date' => '2026-11-18']);
        $this->order($this->client('Outra'), null, [], ['city_visit_id' => $other->id]);
        Passport::actingAs($this->agent);

        $this->getJson("/api/admin/dashboard/orders?visit_id={$this->visit->id}&from=2026-01-01&to=2026-01-31")
            ->assertOk()
            ->assertJsonPath('data.period', ['from' => '2026-11-16', 'to' => '2026-11-17'])
            ->assertJsonPath('data.visit.id', $this->visit->id)
            ->assertJsonPath('data.totals.glasses', 4)
            ->assertJsonCount(1, 'data.by_visit');

        $this->getJson("/api/admin/dashboard/orders?city_id={$this->city->id}")->assertJsonPath('data.totals.glasses', 4);
        $this->getJson('/api/admin/dashboard/orders')->assertJsonPath('data.totals.glasses', 5);
    }

    public function test_financial_part_is_admin_only(): void
    {
        $this->seedSales();

        Passport::actingAs($this->agent);
        $this->getJson('/api/admin/dashboard/financial')->assertForbidden();

        Passport::actingAs(User::factory()->team(UserRole::Admin)->create());
        $this->getJson('/api/admin/dashboard/financial')
            ->assertOk()
            ->assertJsonPath('data.totals.sales', 3)
            ->assertJsonPath('data.totals.glasses', 4)
            // 600 + 300 (o cancelado não conta) + 300 do rascunho
            ->assertJsonPath('data.totals.total', '1200.00')
            ->assertJsonPath('data.totals.paid', '700.00')
            ->assertJsonPath('data.totals.remaining', '200.00')
            ->assertJsonPath('data.totals.draft', '300.00')
            ->assertJsonPath('data.totals.average_ticket', '400.00')
            ->assertJsonPath('data.by_visit.0.visit.title', 'Assaré Novembro')
            ->assertJsonPath('data.by_visit.0.total', '1200.00')
            ->assertJsonPath('data.payment_methods', [
                ['method' => 'cash', 'label' => 'Espécie', 'amount' => '400.00'],
                ['method' => 'pix', 'label' => 'PIX', 'amount' => '200.00'],
                ['method' => 'card', 'label' => 'Cartão', 'amount' => '100.00'],
            ])
            ->assertJsonPath('data.payment_stages', [['stage' => 'down', 'amount' => '300.00'], ['stage' => 'delivery', 'amount' => '400.00']])
            ->assertJsonPath('data.ranking_users.0.user.name', 'Romana')
            ->assertJsonPath('data.ranking_users.0.total', '1200.00');
    }

    public function test_factory_cannot_see_the_dashboards(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::Factory)->create());
        $this->getJson('/api/admin/dashboard/orders')->assertForbidden();
        $this->getJson('/api/admin/dashboard/financial')->assertForbidden();
    }
}
