<?php

namespace Tests\Feature;

use App\Enums\CityVisitStatus;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\Company;
use App\Models\LabConnection;
use App\Models\LabOrder;
use App\Models\PlatformAccess;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Passo 3 do roteiro: a ótica manda pedidos aos laboratórios do grupo (ligação pelo Console, só por ID), com o cliente
 * como paciente; a situação volta pelo aviso do laboratório.
 */
class LabOrderTest extends TestCase
{
    use RefreshDatabase;

    private const LAB = '01JD0000000000000000ROYALL';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** Resposta do laboratório ao pedido: 'ok', 'invalid' ou 'down'. */
    private string $lab = 'ok';

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformAccess::create(['token_hash' => PlatformAccess::hash('token-da-plataforma'), 'paired_at' => now()]);
        $this->company->update(['console_id' => '01JD000000000000000000EXCL']);
        $this->city = City::factory()->create(['name' => 'Iguatu']);
        Http::fake(function (Request $request) {
            $this->sent[] = ['url' => $request->url(), 'method' => $request->method(), 'token' => $request->header('Authorization')[0] ?? null, 'body' => $request->data(), 'multipart' => $request->isMultipart()];
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $this->lab === 'down' => Http::response(['message' => 'Server Error'], 500),
                $path === '/api/integration/catalog' => Http::response(['data' => ['laboratory' => 'Royal Lab', 'treatments' => [['id' => 7, 'name' => 'Antirreflexo']]]]),
                $this->lab === 'invalid' => Http::response(['message' => 'Erro', 'errors' => ['eyes.0.dnp' => ['Informe a DNP (ou autorize as medidas padrão do laboratório).']]], 422),
                str_ends_with($path, '/cancel') => Http::response(['data' => ['number' => '000045', 'status' => 'canceled', 'status_label' => 'Cancelada', 'awaits_frame' => true, 'cancel_reason' => 'Cancelada pela ótica: Desistiu']]),
                // Com o atendimento, o laboratório põe a OS no lote aberto dele.
                default => Http::response(['data' => ['external_id' => '1', 'number' => '000045', 'status' => 'awaiting_measures', 'status_label' => 'Aguardando medidas', 'awaits_frame' => true, 'batch' => isset($request->data()['city_visit_id']) ? 'LT000008' : null]], 201),
            };
        });
    }

    private function link(): string
    {
        $inbound = $this->withToken('token-da-plataforma')->putJson('/api/platform/companies/01JD000000000000000000EXCL/labs/'.self::LAB, ['name' => 'Royal Lab'])
            ->assertOk()->json('data.inbound_token');
        $this->withToken('token-da-plataforma')->putJson('/api/platform/companies/01JD000000000000000000EXCL/labs/'.self::LAB.'/token', ['url' => 'https://lab.test/', 'token' => 'rli_chave_da_otica_no_laboratorio'])
            ->assertOk()->assertJsonPath('data.treatments', 1);
        $this->sent = [];

        return $inbound;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(Client $client, array $overrides = []): array
    {
        return [
            'lab_connection_id' => LabConnection::first()->id,
            'client_id' => $client->id,
            'service_place' => 'internal',
            'lens_type' => 'single_vision',
            'frame_type' => 'full_rim_acetate',
            'measure_frame_in_lab' => true,
            'standard_measures' => true,
            'treatment_ids' => [7],
            'eyes' => [['eye' => 'right', 'sphere' => -2, 'cylinder' => -0.5, 'axis' => 90]],
            ...$overrides,
        ];
    }

    public function test_clients_are_registered_with_name_phone_and_served_city(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        $this->postJson('/api/admin/clients', ['name' => 'Maria'])->assertUnprocessable()->assertJsonValidationErrors(['phone_number', 'city_id']);
        $other = City::factory()->inactive()->create();
        $this->postJson('/api/admin/clients', ['name' => 'Maria', 'phone_number' => '88999990000', 'city_id' => $other->id])
            ->assertJsonValidationErrors(['city_id' => 'Escolha uma cidade atendida pela ótica.']);
        $id = $this->postJson('/api/admin/clients', ['name' => 'Maria Souza', 'phone_number' => '(88) 99999-0000', 'city_id' => $this->city->id])
            ->assertCreated()->assertJsonPath('data.phone_number', '88999990000')->json('data.id');
        $this->patchJson("/api/admin/clients/{$id}", ['name' => 'Maria S. Souza', 'phone_number' => '88999990000', 'city_id' => $this->city->id, 'birth_date' => '1990-05-01'])->assertOk();
        $this->getJson('/api/admin/clients?search=souza')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.birth_date', '1990-05-01');

        // De outra ótica, não aparece.
        $theirs = app(CurrentCompany::class)->within(Company::factory()->create(), fn () => Client::create(['name' => 'Outro', 'phone_number' => '88911112222', 'city_id' => $this->city->id]));
        $this->getJson("/api/admin/clients/{$theirs->id}")->assertNotFound();
    }

    public function test_order_is_saved_pending_then_sent_and_the_status_comes_back(): void
    {
        Storage::fake('local');
        $inbound = $this->link();
        $this->assertSame('Royal Lab', LabConnection::first()->name);
        $client = Client::create(['name' => 'Maria Souza', 'phone_number' => '88999990000', 'city_id' => $this->city->id]);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        $this->getJson('/api/admin/labs')->assertOk()->assertJsonPath('data.0.connected', true);
        $this->getJson('/api/admin/labs/'.LabConnection::first()->id.'/catalog')->assertOk()->assertJsonPath('data.treatments.0.name', 'Antirreflexo');
        $this->sent = [];

        // Salvar não envia (04/11/2026): fica pendente de envio.
        $order = $this->post('/api/admin/lab-orders', [...$this->order($client, ['standard_measures' => false]), 'prescription' => UploadedFile::fake()->image('receita.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.sent', false)->assertJsonPath('data.pending', true)->assertJsonPath('data.status_label', 'Pendente de envio')->json('data');
        $this->assertSame([], $this->sent);
        $this->getJson('/api/admin/lab-orders?not_sent=1')->assertJsonCount(1, 'data');

        // Recusa do laboratório no envio: continua pendente, com o erro.
        $this->lab = 'invalid';
        $this->postJson("/api/admin/lab-orders/{$order['id']}/send")->assertUnprocessable()->assertJsonValidationErrors(['connection' => 'O laboratório recusou: Informe a DNP']);
        $this->getJson("/api/admin/lab-orders/{$order['id']}")->assertJsonPath('data.pending', true)->assertJsonPath('data.send_error', fn ($error) => str_contains($error, 'Informe a DNP'));

        $this->lab = 'ok';
        $this->postJson("/api/admin/lab-orders/{$order['id']}/send")
            ->assertOk()->assertJsonPath('data.lab_number', '000045')->assertJsonPath('data.status_label', 'Aguardando medidas')
            ->assertJsonPath('data.pending', false)->assertJsonPath('data.send_error', null)
            ->assertJsonPath('data.awaits_frame', true)->assertJsonPath('data.cancelable', true);
        $request = collect($this->sent)->last();
        $this->assertSame('https://lab.test/api/integration/service-orders', $request['url']);
        $this->assertSame('Bearer rli_chave_da_otica_no_laboratorio', $request['token']);
        $this->assertTrue($request['multipart']);
        $fields = collect($request['body'])->pluck('contents', 'name');
        $this->assertSame((string) $order['id'], $fields['external_id']);
        $this->assertSame('Maria Souza', $fields['patient_reference']);
        $this->assertSame('-2', $fields['eyes[0][sphere]']);
        $this->assertSame('1', $fields['measure_frame_in_lab']);
        $this->assertArrayNotHasKey('batch_id', $fields->all());
        $this->postJson("/api/admin/lab-orders/{$order['id']}/send")->assertUnprocessable();
        // Enviado não se edita.
        $this->postJson("/api/admin/lab-orders/{$order['id']}", $this->order($client))->assertUnprocessable()->assertJsonValidationErrors(['status']);

        // Aviso do laboratório.
        $status = ['external_id' => (string) $order['id'], 'number' => '000045', 'status' => 'separated', 'status_label' => 'Separada', 'awaits_frame' => false];
        $this->postJson('/api/integration/lab-orders/status', $status)->assertUnauthorized();
        $this->withToken($inbound)->postJson('/api/integration/lab-orders/status', $status)->assertOk();
        $this->withToken('')->getJson("/api/admin/lab-orders/{$order['id']}")->assertOk()
            ->assertJsonPath('data.status_label', 'Separada')->assertJsonPath('data.awaits_frame', false)->assertJsonPath('data.cancelable', false);
        $this->postJson("/api/admin/lab-orders/{$order['id']}/cancel", ['reason' => 'Desistiu'])->assertUnprocessable();
    }

    public function test_pending_order_is_edited_before_sending(): void
    {
        Storage::fake('local');
        $this->link();
        $client = Client::create(['name' => 'Maria Souza', 'phone_number' => '88999990000', 'city_id' => $this->city->id]);
        $other = Client::create(['name' => 'Ana Lima', 'phone_number' => '88999992222', 'city_id' => $this->city->id]);
        $visit = CityVisit::factory()->create(['city_id' => $this->city->id]);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        $order = $this->post('/api/admin/lab-orders', [...$this->order($client), 'prescription' => UploadedFile::fake()->image('receita.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $path = LabOrder::find($order['id'])->prescription_path;

        // Sem receita nova, a anterior fica; o resto troca (inclusive o atendimento).
        $this->postJson("/api/admin/lab-orders/{$order['id']}", $this->order($other, ['service_place' => 'external', 'city_visit_id' => $visit->id, 'notes' => 'Conferido']))
            ->assertOk()->assertJsonPath('data.client.name', 'Ana Lima')->assertJsonPath('data.city_visit.id', $visit->id)
            ->assertJsonPath('data.city_visit.city_id', $this->city->id)->assertJsonPath('data.payload.notes', 'Conferido')->assertJsonPath('data.has_prescription', true);
        $this->assertSame($path, LabOrder::find($order['id'])->prescription_path);
        $this->postJson("/api/admin/lab-orders/{$order['id']}", $this->order($other, ['service_place' => 'external']))->assertJsonValidationErrors(['city_visit_id']);

        // Receita nova troca a anterior.
        $this->post("/api/admin/lab-orders/{$order['id']}", [...$this->order($other), 'prescription' => UploadedFile::fake()->image('nova.jpg')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.city_visit', null);
        Storage::disk('local')->assertMissing($path);

        // Cancelado não se edita nem se envia.
        $this->postJson("/api/admin/lab-orders/{$order['id']}/cancel", ['reason' => 'Desistiu'])->assertOk()->assertJsonPath('data.status_label', 'Cancelada')->assertJsonPath('data.pending', false);
        $this->postJson("/api/admin/lab-orders/{$order['id']}", $this->order($other))->assertJsonValidationErrors(['status']);
        $this->postJson("/api/admin/lab-orders/{$order['id']}/send")->assertJsonValidationErrors(['status']);
        $this->assertSame([], collect($this->sent)->where('url', 'https://lab.test/api/integration/service-orders')->all());
    }

    public function test_orders_of_a_visit_go_together_and_standalone_ones_alone(): void
    {
        $this->link();
        $this->city->update(['state' => 'CE']);
        $client = Client::create(['name' => 'Maria Souza', 'phone_number' => '88999990000', 'city_id' => $this->city->id]);
        $visit = CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Atendimento Novembro', 'visit_date' => today()->subDays(2), 'end_date' => today()->subDay()]);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $save = fn (array $overrides = []) => $this->postJson('/api/admin/lab-orders', $this->order($client, $overrides))->assertCreated()->json('data.id');
        $external = [$save(['service_place' => 'external', 'city_visit_id' => $visit->id]), $save(['service_place' => 'external', 'city_visit_id' => $visit->id])];
        $internal = [$save(), $save()];

        // Pedido de atendimento não vai sozinho (nem marcado): vai pelo atendimento, junto com os outros.
        $this->postJson("/api/admin/lab-orders/{$external[0]}/send")->assertJsonValidationErrors(['status' => 'envie pelo atendimento']);
        $this->postJson('/api/admin/lab-orders/send', ['ids' => [$external[0], $internal[0]]])->assertJsonValidationErrors(['status']);
        $this->getJson("/api/admin/lab-orders?city_visit_id={$visit->id}")->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/lab-orders?standalone=1')->assertJsonCount(2, 'data');

        $this->sent = [];
        $this->postJson("/api/admin/lab-order-visits/{$visit->id}/send")->assertOk()->assertJsonPath('data.sent', 2)->assertJsonPath('data.results.0.error', null);
        // O laboratório põe no lote aberto do atendimento ou abre um, com os dados do atendimento.
        $body = $this->sent[0]['body'];
        $this->assertSame($visit->id, $body['city_visit_id']);
        $this->assertTrue($body['open_batch']);
        $this->assertSame(['title' => 'Atendimento Novembro', 'city' => 'Iguatu', 'state' => 'CE', 'visit_date' => today()->subDays(2)->toDateString(), 'end_date' => today()->subDay()->toDateString()], $body['city_visit']);
        $this->getJson("/api/admin/lab-orders/{$external[1]}")->assertJsonPath('data.lab_batch', 'LT000008')->assertJsonPath('data.sent', true);
        // Nada mais pendente no atendimento.
        $this->postJson("/api/admin/lab-order-visits/{$visit->id}/send")->assertJsonValidationErrors(['orders']);

        // Avulsos: um a um ou marcados, sem lote; quem falhar continua pendente, com o erro.
        $this->lab = 'invalid';
        $this->postJson('/api/admin/lab-orders/send', ['ids' => $internal])->assertOk()->assertJsonPath('data.sent', 0)
            ->assertJsonPath('data.results.1.error', fn ($error) => str_contains($error, 'O laboratório recusou'));
        $this->lab = 'ok';
        $this->sent = [];
        $this->postJson('/api/admin/lab-orders/send', ['ids' => $internal])->assertOk()->assertJsonPath('data.sent', 2);
        $this->assertFalse($this->sent[0]['body']['open_batch']);
        $this->assertArrayNotHasKey('city_visit_id', $this->sent[0]['body']);
        $this->getJson('/api/admin/lab-orders?not_sent=1')->assertJsonCount(0, 'data');
    }

    public function test_visits_receiving_orders_are_listed_and_can_be_closed(): void
    {
        $this->link();
        $client = Client::create(['name' => 'Maria Souza', 'phone_number' => '88999990000', 'city_id' => $this->city->id]);
        $other = City::factory()->create(['name' => 'Crato']);
        $old = CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Outubro', 'visit_date' => today()->subDays(20), 'end_date' => today()->subDays(18), 'status' => CityVisitStatus::Completed]);
        $now = CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Novembro', 'visit_date' => today()]);
        CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Futuro', 'visit_date' => today()->addDays(5)]);
        CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Cancelado', 'visit_date' => today()->subDay(), 'status' => CityVisitStatus::Cancelled]);
        CityVisit::factory()->create(['city_id' => $other->id, 'title' => 'Crato', 'visit_date' => today()->subDay()]);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->postJson('/api/admin/lab-orders', $this->order($client, ['service_place' => 'external', 'city_visit_id' => $old->id]))->assertCreated();

        // Desde que é cadastrado (não espera o 1º dia), não cancelado e sem "Encerrar pedidos" (concluído continua: os
        // pedidos chegam depois). Do mais novo ao mais antigo.
        $this->getJson("/api/admin/lab-order-visits?city_id={$this->city->id}")->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.title', 'Futuro')
            ->assertJsonPath('data.1.title', 'Novembro')
            ->assertJsonPath('data.2.title', 'Outubro')
            ->assertJsonPath('data.2.pending_count', 1)
            ->assertJsonPath('data.2.sent_count', 0)
            ->assertJsonPath('data.2.city.name', 'Iguatu');
        $this->getJson('/api/admin/lab-order-visits')->assertJsonCount(4, 'data');

        // Com pedido pendente, não encerra.
        $this->postJson("/api/admin/lab-order-visits/{$old->id}/close")->assertJsonValidationErrors(['orders']);
        $this->postJson("/api/admin/lab-order-visits/{$now->id}/close")->assertOk()->assertJsonPath('data.accepts_orders', false);
        $this->getJson("/api/admin/lab-order-visits?city_id={$this->city->id}")->assertJsonCount(2, 'data');
        // Encerrado não recebe pedido novo; reabrir volta.
        $this->postJson('/api/admin/lab-orders', $this->order($client, ['service_place' => 'external', 'city_visit_id' => $now->id]))->assertJsonValidationErrors(['city_visit_id']);
        $this->deleteJson("/api/admin/lab-order-visits/{$now->id}/close")->assertOk()->assertJsonPath('data.accepts_orders', true);
    }

    public function test_lab_lists_the_city_visits_with_the_inbound_key(): void
    {
        $inbound = $this->link();
        $this->city->update(['state' => 'CE']);
        CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Atendimento Outubro', 'visit_date' => '2026-10-15', 'end_date' => '2026-10-17']);
        CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Atendimento Novembro', 'visit_date' => '2026-11-22']);
        CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Cancelado', 'visit_date' => '2026-12-01', 'status' => CityVisitStatus::Cancelled]);

        $this->getJson('/api/integration/city-visits')->assertUnauthorized();
        $this->withToken($inbound)->getJson('/api/integration/city-visits')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Atendimento Novembro')
            ->assertJsonPath('data.1.title', 'Atendimento Outubro')
            ->assertJsonPath('data.1.city', 'Iguatu')
            ->assertJsonPath('data.1.state', 'CE')
            ->assertJsonPath('data.1.visit_date', '2026-10-15')
            ->assertJsonPath('data.1.end_date', '2026-10-17');
    }

    public function test_order_takes_the_city_visit_to_the_lab_and_shows_the_batch(): void
    {
        $this->link();
        $client = Client::create(['name' => 'Maria Souza', 'phone_number' => '88999990000', 'city_id' => $this->city->id]);
        $visit = CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Atendimento Novembro', 'visit_date' => '2026-11-22']);
        $canceled = CityVisit::factory()->create(['city_id' => $this->city->id, 'status' => CityVisitStatus::Cancelled]);
        $theirs = app(CurrentCompany::class)->within(Company::factory()->create(), fn () => CityVisit::factory()->create(['city_id' => $this->city->id]));
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        foreach ([$canceled->id, $theirs->id] as $id) {
            $this->postJson('/api/admin/lab-orders', $this->order($client, ['service_place' => 'external', 'city_visit_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors(['city_visit_id']);
        }
        // Interno ou externo é obrigatório; externo exige o atendimento; interno não aceita atendimento (04/11/2026).
        $this->postJson('/api/admin/lab-orders', $this->order($client, ['service_place' => null]))->assertUnprocessable()->assertJsonValidationErrors(['service_place' => 'interno ou externo']);
        $this->postJson('/api/admin/lab-orders', $this->order($client, ['service_place' => 'external']))->assertUnprocessable()->assertJsonValidationErrors(['city_visit_id' => 'escolha a cidade e o atendimento']);
        $this->postJson('/api/admin/lab-orders', $this->order($client, ['service_place' => 'internal', 'city_visit_id' => $visit->id]))->assertUnprocessable()->assertJsonValidationErrors(['city_visit_id']);
        $id = $this->postJson('/api/admin/lab-orders', $this->order($client, ['service_place' => 'external', 'city_visit_id' => $visit->id]))
            ->assertCreated()
            ->assertJsonPath('data.city_visit.title', 'Atendimento Novembro')
            ->assertJsonPath('data.city_visit.city', 'Iguatu')
            ->assertJsonMissingPath('data.payload.city_visit_id')
            ->assertJsonMissingPath('data.payload.service_place')->json('data.id');
        $this->postJson("/api/admin/lab-order-visits/{$visit->id}/send")->assertOk()->assertJsonPath('data.sent', 1);
        $this->getJson("/api/admin/lab-orders/{$id}")->assertJsonPath('data.lab_batch', 'LT000008');
        $this->assertSame($visit->id, collect($this->sent)->last()['body']['city_visit_id']);
        $this->assertArrayNotHasKey('service_place', collect($this->sent)->last()['body']);

        // Interno: sem atendimento, avulsa.
        $id = $this->postJson('/api/admin/lab-orders', $this->order($client))->assertCreated()->assertJsonPath('data.city_visit', null)->json('data.id');
        $this->postJson("/api/admin/lab-orders/{$id}/send")->assertOk()->assertJsonPath('data.lab_batch', null);
        $this->assertArrayNotHasKey('city_visit_id', collect($this->sent)->last()['body']);
    }

    public function test_empty_fields_are_not_sent_to_the_lab(): void
    {
        $this->link();
        $client = Client::create(['name' => 'Maria Souza', 'phone_number' => '88999990000', 'city_id' => $this->city->id]);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        // Como o painel manda a visão simples: o que não cabe vai nulo (o laboratório recusa adição/bifocal mesmo nulos).
        $this->postJson('/api/admin/lab-orders', $this->order($client, [
            'bifocal_style' => null,
            'material' => null,
            'refractive_index' => null,
            'frame_brand' => null,
            'notes' => null,
            'eyes' => [['eye' => 'right', 'sphere' => 1, 'cylinder' => -1.5, 'axis' => 10, 'addition' => null, 'prism' => null, 'prism_base' => null, 'dnp' => 32, 'height' => 20, 'near_dnp' => null]],
        ]))->assertCreated();
        $this->postJson('/api/admin/lab-orders/'.LabOrder::latest('id')->value('id').'/send')->assertOk();

        $body = collect($this->sent)->last()['body'];
        $this->assertArrayNotHasKey('bifocal_style', $body);
        $this->assertArrayNotHasKey('notes', $body);
        $this->assertSame(['eye' => 'right', 'sphere' => 1, 'cylinder' => -1.5, 'axis' => 10, 'dnp' => 32, 'height' => 20], $body['eyes'][0]);
    }

    public function test_send_failure_keeps_it_pending_and_cancel_goes_to_the_lab(): void
    {
        $this->link();
        $client = Client::create(['name' => 'João', 'phone_number' => '88999991111', 'city_id' => $this->city->id]);
        Passport::actingAs(User::factory()->team()->create());

        $this->lab = 'down';
        $order = $this->postJson('/api/admin/lab-orders', $this->order($client))->assertCreated()
            ->assertJsonPath('data.sent', false)->assertJsonPath('data.status_label', 'Pendente de envio')->json('data');
        $this->postJson("/api/admin/lab-orders/{$order['id']}/send")->assertUnprocessable();
        $this->assertStringContainsString('Royal Lab recusou', LabOrder::find($order['id'])->send_error);

        $this->lab = 'ok';
        $this->postJson("/api/admin/lab-orders/{$order['id']}/send")->assertOk()->assertJsonPath('data.sent', true)->assertJsonPath('data.send_error', null);
        $this->postJson("/api/admin/lab-orders/{$order['id']}/send")->assertUnprocessable();
        $this->assertFalse(collect($this->sent)->last()['multipart']);

        $this->postJson("/api/admin/lab-orders/{$order['id']}/cancel")->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/admin/lab-orders/{$order['id']}/cancel", ['reason' => 'Desistiu'])->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertSame('https://lab.test/api/integration/service-orders/'.$order['id'].'/cancel', collect($this->sent)->last()['url']);
        $this->getJson('/api/admin/lab-orders?search=joão')->assertOk()->assertJsonCount(1, 'data');

        // Parceiro não lança pedido.
        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/admin/lab-orders')->assertForbidden();
    }
}
