<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\City;
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
                default => Http::response(['data' => ['external_id' => '1', 'number' => '000045', 'status' => 'awaiting_measures', 'status_label' => 'Aguardando medidas', 'awaits_frame' => true]], 201),
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

    public function test_order_goes_to_the_lab_and_the_status_comes_back(): void
    {
        Storage::fake('local');
        $inbound = $this->link();
        $this->assertSame('Royal Lab', LabConnection::first()->name);
        $client = Client::create(['name' => 'Maria Souza', 'phone_number' => '88999990000', 'city_id' => $this->city->id]);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        $this->getJson('/api/admin/labs')->assertOk()->assertJsonPath('data.0.connected', true);
        $this->getJson('/api/admin/labs/'.LabConnection::first()->id.'/catalog')->assertOk()->assertJsonPath('data.treatments.0.name', 'Antirreflexo');
        $this->sent = [];

        // Recusa do laboratório volta ao formulário; nada fica salvo.
        $this->lab = 'invalid';
        $this->postJson('/api/admin/lab-orders', $this->order($client, ['standard_measures' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors(['eyes.0.dnp']);
        $this->assertSame(0, LabOrder::count());

        $this->lab = 'ok';
        $order = $this->post('/api/admin/lab-orders', [...$this->order($client), 'prescription' => UploadedFile::fake()->image('receita.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.lab_number', '000045')->assertJsonPath('data.status_label', 'Aguardando medidas')
            ->assertJsonPath('data.awaits_frame', true)->assertJsonPath('data.cancelable', true)->json('data');
        $request = collect($this->sent)->last();
        $this->assertSame('https://lab.test/api/integration/service-orders', $request['url']);
        $this->assertSame('Bearer rli_chave_da_otica_no_laboratorio', $request['token']);
        $this->assertTrue($request['multipart']);
        $fields = collect($request['body'])->pluck('contents', 'name');
        $this->assertSame((string) $order['id'], $fields['external_id']);
        $this->assertSame('Maria Souza', $fields['patient_reference']);
        $this->assertSame('-2', $fields['eyes[0][sphere]']);
        $this->assertSame('1', $fields['measure_frame_in_lab']);

        // Aviso do laboratório.
        $status = ['external_id' => (string) $order['id'], 'number' => '000045', 'status' => 'separated', 'status_label' => 'Separada', 'awaits_frame' => false];
        $this->postJson('/api/integration/lab-orders/status', $status)->assertUnauthorized();
        $this->withToken($inbound)->postJson('/api/integration/lab-orders/status', $status)->assertOk();
        $this->withToken('')->getJson("/api/admin/lab-orders/{$order['id']}")->assertOk()
            ->assertJsonPath('data.status_label', 'Separada')->assertJsonPath('data.awaits_frame', false)->assertJsonPath('data.cancelable', false);
        $this->postJson("/api/admin/lab-orders/{$order['id']}/cancel", ['reason' => 'Desistiu'])->assertUnprocessable();
    }

    public function test_order_not_sent_is_kept_and_sent_again_and_cancel_goes_to_the_lab(): void
    {
        $this->link();
        $client = Client::create(['name' => 'João', 'phone_number' => '88999991111', 'city_id' => $this->city->id]);
        Passport::actingAs(User::factory()->team()->create());

        $this->lab = 'down';
        $order = $this->postJson('/api/admin/lab-orders', $this->order($client))->assertCreated()
            ->assertJsonPath('data.sent', false)->assertJsonPath('data.status_label', 'Não enviado')->json('data');
        $this->assertStringContainsString('Royal Lab recusou', $order['send_error']);
        $this->postJson("/api/admin/lab-orders/{$order['id']}/send")->assertUnprocessable();

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
