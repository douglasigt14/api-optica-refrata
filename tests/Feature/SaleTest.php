<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\LabConnection;
use App\Models\LabOrder;
use App\Models\PlatformAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Vendas da ótica — a promissória (07/11/2026): rascunho com o cliente que paga e os óculos (paciente e valor), lançar
 * (entrada + a receber no Financeiro), ajustes sozinhos até os óculos irem ao laboratório, entregar e cancelar.
 */
class SaleTest extends TestCase
{
    use RefreshDatabase;

    private const LAB = '01JD0000000000000000ROYALL';

    /** @var list<array{url: string, method: string, body: array<string, mixed>}> */
    private array $financial = [];

    /** Baixas feitas no Financeiro, por ficha (o que `GET …/sales/payments` devolve). @var array<string, mixed> */
    private array $remote = [];

    /** @var list<array<string, mixed>> */
    private array $pulls = [];

    /** Resposta do financeiro: 'ok' ou 'down'. */
    private string $fin = 'ok';

    private City $city;

    private Client $mother;

    private Client $child;

    private CityVisit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformAccess::create(['token_hash' => PlatformAccess::hash('token-da-plataforma'), 'paired_at' => now()]);
        $this->company->update(['console_id' => '01JD000000000000000000EXCL']);
        $this->city = City::factory()->create(['name' => 'Cariús', 'state' => 'CE']);
        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($host === 'fin.test' && $path === '/api/integrations/optica/sales/payments') {
                // A ficha puxa as baixas feitas no Financeiro (09/11/2026): à parte das chamadas que mudam algo lá.
                $this->pulls[] = $request->data();

                return $this->fin === 'down' ? Http::response(['message' => 'Server Error'], 500) : Http::response(['data' => (object) $this->remote]);
            }
            if ($host === 'fin.test') {
                $this->financial[] = ['url' => $path, 'method' => $request->method(), 'body' => $request->data()];
                if ($this->fin === 'down') {
                    return Http::response(['message' => 'Server Error'], 500);
                }

                return match (true) {
                    $path === '/api/integrations/optica/accounts' => Http::response(['data' => [['id' => 1, 'name' => 'Caixa físico', 'kind' => 'cash'], ['id' => 2, 'name' => 'Banco A', 'kind' => 'bank']]]),
                    default => Http::response(['data' => ['status' => 'partial']]),
                };
            }

            return match (true) {
                $path === '/api/integration/catalog' => Http::response(['data' => ['laboratory' => 'Royal Lab', 'treatments' => [['id' => 7, 'name' => 'Antirreflexo']]]]),
                default => Http::response(['data' => ['external_id' => '1', 'number' => '000045', 'status' => 'awaiting_measures', 'status_label' => 'Aguardando medidas', 'awaits_frame' => true, 'batch' => 'LT000008']], 201),
            };
        });
        $this->withToken('token-da-plataforma')->putJson('/api/platform/companies/01JD000000000000000000EXCL/labs/'.self::LAB, ['name' => 'Royal Lab'])->assertOk();
        $this->withToken('token-da-plataforma')->putJson('/api/platform/companies/01JD000000000000000000EXCL/labs/'.self::LAB.'/token', ['url' => 'https://lab.test/', 'token' => 'rli_chave_da_otica_no_laboratorio'])->assertOk();
        // O Console liga a ótica ao financeiro (endereço e chave no banco, nunca no .env).
        $this->withToken('token-da-plataforma')->putJson('/api/platform/companies/01JD000000000000000000EXCL/financial-connection', ['url' => 'https://fin.test/', 'token' => 'rfo_chave_da_otica_no_financeiro'])
            ->assertOk()->assertJsonPath('data.accounts', 2);
        $this->financial = [];

        $this->mother = Client::create(['name' => 'Luiza da Silva Sousa', 'phone_number' => '88996358639', 'cpf' => '06410307381', 'address' => 'Acampamento', 'city_id' => $this->city->id]);
        $this->child = Client::create(['name' => 'Pedro Talisson Silva Santos', 'birth_date' => '2016-10-02', 'city_id' => $this->city->id]);
        $this->visit = CityVisit::factory()->create(['city_id' => $this->city->id, 'title' => 'Atendimento Novembro']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function glasses(int $saleId, Client $patient, float $price, array $overrides = []): array
    {
        return [
            'lab_connection_id' => LabConnection::first()->id,
            'client_id' => $patient->id,
            'service_place' => 'external',
            'city_visit_id' => $this->visit->id,
            'sale_id' => $saleId,
            'price' => $price,
            'lens_type' => 'single_vision',
            'frame_type' => 'full_rim_acetate',
            'measure_frame_in_lab' => true,
            'standard_measures' => true,
            'eyes' => [['eye' => 'right', 'sphere' => -2]],
            ...$overrides,
        ];
    }

    private function newSale(array $overrides = []): int
    {
        return $this->postJson('/api/admin/sales', ['client_id' => $this->mother->id, 'city_visit_id' => $this->visit->id, 'expected_delivery_date' => today()->addDays(15)->toDateString(), ...$overrides])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    /**
     * Entrega prevista do atendimento (09/11/2026): a ficha nasce com a do atendimento (último dia + 5, ou a definida) e
     * acompanha quando ela muda; nas lançadas, o vencimento no Financeiro também. A da loja continua com a sua.
     */
    public function test_expected_delivery_comes_from_the_visit_and_follows_it(): void
    {
        $this->visit->update(['visit_date' => '2026-11-16', 'end_date' => '2026-11-17']);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());

        // Sem data na ficha do atendimento: a do atendimento (17/11 + 5); a que vier é ignorada.
        $sale = $this->postJson('/api/admin/sales', ['client_id' => $this->mother->id, 'city_visit_id' => $this->visit->id])
            ->assertCreated()->assertJsonPath('data.expected_delivery_date', '2026-11-22')->assertJsonPath('data.delivery_from_visit', true)->json('data.id');
        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}")->assertJsonPath('data.expected_delivery_date', '2026-11-22');
        $this->patchJson("/api/admin/sales/{$sale}", ['client_id' => $this->mother->id, 'expected_delivery_date' => '2026-12-30'])->assertOk()->assertJsonPath('data.expected_delivery_date', '2026-11-22');
        // Da loja: informa a sua.
        $this->postJson('/api/admin/sales', ['client_id' => $this->mother->id])->assertJsonValidationErrors(['expected_delivery_date']);

        // Lançada: trocar no atendimento troca a ficha e o vencimento do restante no Financeiro.
        $this->company->update(['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]]);
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 600))->assertCreated();
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'pix', 'amount' => 200]]])->assertOk();
        $other = $this->postJson('/api/admin/sales', ['client_id' => $this->child->id, 'city_visit_id' => $this->visit->id])->assertCreated()->json('data.id');
        $this->financial = [];
        $this->putJson("/api/admin/lab-order-visits/{$this->visit->id}/delivery", ['expected_delivery_date' => '2026-11-28'])
            ->assertOk()->assertJsonPath('data.expected_delivery_date', '2026-11-28')->assertJsonPath('data.updated', 2)->assertJsonPath('data.failed', []);
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonPath('data.expected_delivery_date', '2026-11-28');
        $this->getJson("/api/admin/sales/{$other}")->assertJsonPath('data.expected_delivery_date', '2026-11-28');
        $this->assertNotEmpty($this->financial, 'A ficha lançada ajusta o Financeiro.');
        $this->assertStringContainsString('2026-11-28', json_encode($this->financial));
        // Ficha nova já nasce com a data nova.
        $this->postJson('/api/admin/sales', ['client_id' => $this->mother->id, 'city_visit_id' => $this->visit->id])->assertJsonPath('data.expected_delivery_date', '2026-11-28');
    }

    /** Relatório do atendimento: só as fichas dele, pago por forma de pagamento e o que fica para a entrega. */
    public function test_the_visit_report_lists_its_sales_with_payments_and_what_is_left_for_delivery(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->company->update(['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]]);
        $launched = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($launched, $this->mother, 600))->assertCreated();
        $this->postJson("/api/admin/sales/{$launched}/launch", ['payments' => [['method' => 'pix', 'amount' => 200], ['method' => 'card', 'amount' => 100, 'installments' => 3]]])->assertOk();
        $draft = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($draft, $this->child, 300))->assertCreated();
        // Ficha de outro atendimento não entra.
        $other = CityVisit::factory()->create(['city_id' => $this->city->id]);
        $this->postJson('/api/admin/sales', ['client_id' => $this->mother->id, 'city_visit_id' => $other->id])->assertCreated();

        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}/sales-report")
            ->assertOk()
            ->assertJsonCount(2, 'data.sales')
            ->assertJsonPath('data.count.launched', 1)
            ->assertJsonPath('data.count.draft', 1)
            ->assertJsonPath('data.sales.0.id', $launched)
            ->assertJsonPath('data.sales.0.orders.0.patient', $this->mother->name)
            ->assertJsonPath('data.sales.0.orders.0.price', '600.00')
            ->assertJsonPath('data.sales.0.orders.0.lens_cleaner', 0)
            ->assertJsonPath('data.sales.0.client.phone_number', $this->mother->phone_number)
            ->assertJsonPath('data.sales.0.paid', '300.00')
            ->assertJsonPath('data.sales.0.remaining', '300.00')
            ->assertJsonPath('data.sales.0.payments.1.method_label', 'Cartão')
            ->assertJsonPath('data.sales.0.payments.1.installments', 3)
            ->assertJsonPath('data.sales.1.remaining', '0.00')
            ->assertJsonPath('data.totals.total', '900.00')
            ->assertJsonPath('data.totals.paid', '300.00')
            ->assertJsonPath('data.totals.remaining', '300.00')
            ->assertJsonPath('data.totals.draft', '300.00')
            ->assertJsonPath('data.totals.methods.1.method', 'pix')
            ->assertJsonPath('data.totals.methods.1.amount', '200.00')
            ->assertJsonPath('data.totals.methods.2.amount', '100.00');
    }

    /** Baixa feita no Financeiro (09/11/2026): entra na ficha, quita sem entregar e volta ao Financeiro com o id de lá. */
    public function test_a_payment_made_in_the_financial_enters_the_sale_and_settles_it_without_delivering(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->company->update(['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]]);
        $sale = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 600))->assertCreated();
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'pix', 'amount' => 200]]])->assertOk()->assertJsonPath('data.settled', false);

        // Baixa dos 400 no Financeiro (conta 1, forma "Pix"): ao abrir, a ficha mostra pago e "Quitado", ainda lançada.
        $this->remote = [(string) $sale => ['canceled' => false, 'payments' => [['id' => 77, 'amount' => '400.00', 'paid_at' => '2026-11-10', 'account_id' => 1, 'payment_method' => 'Pix']]]];
        $this->getJson("/api/admin/sales/{$sale}")
            ->assertOk()
            ->assertJsonPath('data.status', 'launched')
            ->assertJsonPath('data.paid', '600.00')
            ->assertJsonPath('data.remaining', '0.00')
            ->assertJsonPath('data.settled', true)
            ->assertJsonPath('data.payments.0.kind', 'down')
            ->assertJsonPath('data.payments.1.method', 'pix')
            ->assertJsonPath('data.payments.1.kind', 'financial')
            ->assertJsonPath('data.payments.1.from_financial', true)
            ->assertJsonPath('data.payments.1.removable', false);
        // Abrir de novo não duplica; não se apaga por aqui.
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonCount(2, 'data.payments');
        $remote = $this->getJson("/api/admin/sales/{$sale}")->json('data.payments.1.id');
        $this->deleteJson("/api/admin/sales/{$sale}/payments/{$remote}")->assertJsonValidationErrors(['payment']);
        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}/sales-report")->assertJsonPath('data.totals.remaining', '0.00')->assertJsonPath('data.sales.0.settled', true);

        // Entregar sem receber nada; no estado que vai ao Financeiro, a baixa de lá volta com o id e a conta de lá.
        $this->financial = [];
        $this->postJson("/api/admin/sales/{$sale}/deliver", ['payments' => []])->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.settled', false);
        $body = collect($this->financial)->firstWhere('method', 'PUT')['body'];
        $this->assertSame(77, $body['payments'][1]['financial_payment_id']);
        $this->assertSame(1, $body['payments'][1]['account_id']);
        $this->assertSame((string) $remote, $body['payments'][1]['id']);
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonPath('data.payments.1.removable', true);
    }

    /** O pago ao lançar é a "Entrada"; o pago depois, "Pagamento avulso" (09/11/2026). */
    public function test_the_down_payment_is_told_apart_from_a_later_payment(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->company->update(['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]]);
        $sale = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 600))->assertCreated();
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'pix', 'amount' => 200]]])->assertOk()->assertJsonPath('data.payments.0.kind', 'down');
        $this->travel(1)->minutes();
        $this->postJson("/api/admin/sales/{$sale}/payments", ['method' => 'cash', 'amount' => 50])
            ->assertOk()->assertJsonPath('data.payments.0.kind', 'down')->assertJsonPath('data.payments.1.kind', 'extra');
        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}/sales-report")->assertJsonPath('data.sales.0.payments.1.kind', 'extra');
    }

    /** Entrada apagada (erro de digitação): o próximo pagamento vira a entrada; os seguintes, avulsos (24/11/2026). */
    public function test_a_payment_after_the_down_payment_is_removed_becomes_the_down_payment(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->company->update(['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]]);
        $sale = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 600))->assertCreated();
        $payment = $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'pix', 'amount' => 200]]])->assertOk()->json('data.payments.0.id');
        $this->deleteJson("/api/admin/sales/{$sale}/payments/{$payment}")->assertOk()->assertJsonCount(0, 'data.payments');
        $this->travel(1)->minutes();
        $this->postJson("/api/admin/sales/{$sale}/payments", ['method' => 'pix', 'amount' => 20])->assertOk()->assertJsonPath('data.payments.0.kind', 'down');
        $this->travel(1)->minutes();
        $this->postJson("/api/admin/sales/{$sale}/payments", ['method' => 'cash', 'amount' => 50])
            ->assertOk()->assertJsonPath('data.payments.0.kind', 'down')->assertJsonPath('data.payments.1.kind', 'extra');
    }

    /** Baixa de lá estornada antes de voltar: sai da ficha. Financeiro fora do ar: a ficha abre com o que tem. */
    public function test_a_financial_payment_reversed_there_leaves_the_sale(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->company->update(['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]]);
        $sale = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 600))->assertCreated();
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => []])->assertOk();
        $this->remote = [(string) $sale => ['canceled' => false, 'payments' => [['id' => 80, 'amount' => '150.00', 'paid_at' => '2026-11-10', 'account_id' => 1, 'payment_method' => 'Boleto']]]];
        // Forma que a ótica não tem: pela conta (1 = a de Espécie).
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonPath('data.paid', '150.00')->assertJsonPath('data.payments.0.method', 'cash');

        $this->fin = 'down';
        $this->getJson("/api/admin/sales/{$sale}")->assertOk()->assertJsonPath('data.paid', '150.00');
        // Para mudar a ficha, precisa falar com o Financeiro.
        $this->postJson("/api/admin/sales/{$sale}/payments", ['method' => 'pix', 'amount' => 50])->assertJsonValidationErrors(['financial']);

        $this->fin = 'ok';
        $this->remote = [];
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonPath('data.paid', '0.00')->assertJsonCount(0, 'data.payments');
    }

    /** OS do atendimento sem ficha (as de antes da ficha existir) entra na ficha, mesmo já enviada, com o valor. */
    public function test_an_order_of_the_visit_without_sale_is_attached_to_a_sale(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $sale = $this->newSale();
        $lab = LabConnection::first()->id;
        $loose = LabOrder::create(['lab_connection_id' => $lab, 'client_id' => $this->child->id, 'city_visit_id' => $this->visit->id, 'payload' => [], 'sent_at' => now(), 'status' => 'separated', 'status_label' => 'Separada']);
        $otherVisit = CityVisit::factory()->create(['city_id' => $this->city->id]);
        $elsewhere = LabOrder::create(['lab_connection_id' => $lab, 'client_id' => $this->child->id, 'city_visit_id' => $otherVisit->id, 'payload' => []]);

        $this->postJson("/api/admin/sales/{$sale}/orders", ['lab_order_id' => $loose->id])->assertJsonValidationErrors(['price']);
        $this->postJson("/api/admin/sales/{$sale}/orders", ['lab_order_id' => $elsewhere->id, 'price' => 300])->assertJsonValidationErrors(['lab_order_id']);
        $this->postJson("/api/admin/sales/{$sale}/orders", ['lab_order_id' => $loose->id, 'price' => 450])
            ->assertOk()->assertJsonPath('data.total', '450.00')->assertJsonPath('data.orders.0.id', $loose->id)->assertJsonPath('data.locked', true);
        // Já está numa ficha: não entra de novo.
        $this->postJson("/api/admin/sales/{$this->newSale()}/orders", ['lab_order_id' => $loose->id, 'price' => 450])->assertJsonValidationErrors(['lab_order_id']);
        // Ficha da loja não recebe.
        $store = $this->postJson('/api/admin/sales', ['client_id' => $this->mother->id, 'expected_delivery_date' => today()->addDays(5)->toDateString()])->json('data.id');
        $this->postJson("/api/admin/sales/{$store}/orders", ['lab_order_id' => $elsewhere->id, 'price' => 300])->assertJsonValidationErrors(['lab_order_id' => 'Só a ficha de um atendimento']);
    }

    public function test_draft_with_two_glasses_is_launched_with_the_down_payment_and_adjusts_the_financial(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $sale = $this->newSale();

        // Óculos da mãe e do filho (o paciente pode ser outra pessoa); o valor é obrigatório e o atendimento é o da venda.
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 600, ['price' => null]))->assertJsonValidationErrors(['price']);
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 600, ['service_place' => 'internal', 'city_visit_id' => null]))->assertJsonValidationErrors(['sale_id']);
        $first = $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 600))->assertCreated()->assertJsonPath('data.sale_id', $sale)->json('data.id');
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->child, 600))->assertCreated();
        $this->getJson("/api/admin/sales/{$sale}")->assertOk()->assertJsonPath('data.total', '1200.00')->assertJsonPath('data.orders.1.patient.name', 'Pedro Talisson Silva Santos');
        // Na lista (tela do atendimento, vendas em acordeão): os óculos com o laboratório e a OS de Ótica; até 100 por página.
        $this->getJson('/api/admin/sales?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('data.0.orders.0.number', 1)->assertJsonPath('data.0.orders.0.lab.name', fn ($name) => filled($name))
            ->assertJsonPath('data.0.orders.0.lens.lens_type', fn ($type) => filled($type))
            // Etiquetas de conferência: sem foto da receita e com "medir no laboratório" (sem as medidas da armação).
            ->assertJsonPath('data.0.orders.0.has_prescription', false)->assertJsonPath('data.0.orders.0.has_frame_measures', false);
        $this->getJson('/api/admin/sales?per_page=101')->assertJsonValidationErrors(['per_page']);
        // Rascunho: nada vai ao Financeiro.
        $this->assertSame([], $this->financial);

        // Lançar: precisa das contas do Financeiro para as formas usadas (só o admin configura).
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'pix', 'amount' => 200], ['method' => 'card', 'amount' => 400, 'installments' => 6]]])
            ->assertJsonValidationErrors(['payments' => 'Escolha em Configurações a conta do Financeiro para PIX (só o admin).']);
        $this->putJson('/api/admin/sale-settings', ['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]])->assertForbidden();
        Passport::actingAs(User::factory()->team(UserRole::Admin)->create());
        $this->putJson('/api/admin/sale-settings', ['sale_accounts' => ['pix' => 2, 'card' => 9]])->assertJsonValidationErrors(['sale_accounts.card']);
        $this->putJson('/api/admin/sale-settings', ['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]])->assertOk()
            ->assertJsonPath('data.connected', true)->assertJsonCount(2, 'data.accounts')->assertJsonPath('data.sale_accounts.cash', 1);
        $this->financial = [];

        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'pix', 'amount' => 1300]]])->assertJsonValidationErrors(['payments']);
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'pix', 'amount' => 200], ['method' => 'card', 'amount' => 400, 'installments' => 6]]])
            ->assertOk()->assertJsonPath('data.status', 'launched')->assertJsonPath('data.paid', '600.00')->assertJsonPath('data.remaining', '600.00')
            ->assertJsonPath('data.payments.1.installments', 6);
        $body = collect($this->financial)->firstWhere('method', 'PUT')['body'];
        $this->assertSame("/api/integrations/optica/sales/{$sale}", $this->financial[0]['url']);
        $this->assertSame('1200.00', $body['amount']);
        $this->assertSame(['name' => 'Luiza da Silva Sousa', 'document' => '06410307381', 'phone' => '88996358639', 'address' => 'Acampamento'], $body['customer']);
        $this->assertSame([2, 2], array_column($body['payments'], 'account_id'));
        $this->assertSame(today()->addDays(15)->toDateString(), $body['due_date']);

        // Mudou o valor de um óculos depois de lançar: o Financeiro é ajustado junto.
        $this->financial = [];
        $this->postJson("/api/admin/lab-orders/{$first}", $this->glasses($sale, $this->mother, 700))->assertOk();
        $this->assertSame('1300.00', $this->financial[0]['body']['amount']);
        // Financeiro fora do ar: não muda nada aqui.
        $this->fin = 'down';
        $this->postJson("/api/admin/sales/{$sale}/payments", ['method' => 'cash', 'amount' => 100])->assertJsonValidationErrors(['financial']);
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonCount(2, 'data.payments');
        $this->fin = 'ok';

        // Enviado ao laboratório: óculos e valores travam e não cancela mais.
        $this->postJson("/api/admin/lab-order-visits/{$this->visit->id}/send")->assertOk()->assertJsonPath('data.sent', 2);
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonPath('data.locked', true);
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->child, 300))->assertJsonValidationErrors(['sale_id']);
        $this->postJson("/api/admin/sales/{$sale}/cancel", ['reason' => 'Desistiu'])->assertJsonValidationErrors(['status']);

        // Entregar: recebe exatamente o que falta (pode misturar formas); o Financeiro recebe a baixa.
        $this->postJson("/api/admin/sales/{$sale}/deliver", ['payments' => [['method' => 'cash', 'amount' => 100]]])->assertJsonValidationErrors(['payments']);
        $this->financial = [];
        $this->postJson("/api/admin/sales/{$sale}/deliver", ['payments' => [['method' => 'cash', 'amount' => 300], ['method' => 'pix', 'amount' => 400]]])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.remaining', '0.00');
        $this->assertCount(4, $this->financial[0]['body']['payments']);
        $this->postJson("/api/admin/sales/{$sale}/payments", ['method' => 'cash', 'amount' => 1])->assertJsonValidationErrors(['sale_id']);
    }

    /**
     * Cliente que paga sem CPF (14/11/2026: pode vir com outro documento): a ficha lança e vai ao Financeiro sem documento.
     */
    /** Etiquetas de conferência (18/11/2026): a ficha diz, por óculos, se já tem a foto da receita e as medidas da armação. */
    public function test_each_glasses_tells_if_it_has_the_prescription_photo_and_the_frame_measures(): void
    {
        Storage::fake('local');
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $sale = $this->newSale();

        $bare = $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 300))->assertCreated()->json('data.id');
        $done = $this->post('/api/admin/lab-orders', $this->glasses($sale, $this->child, 300, [
            'measure_frame_in_lab' => false, 'frame_a' => 52, 'frame_bridge' => 18, 'frame_ed' => 55, 'frame_b' => 40,
            'prescription' => UploadedFile::fake()->image('receita.jpg'),
        ]), ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $orders = collect($this->getJson("/api/admin/sales/{$sale}")->assertOk()->json('data.orders'))->keyBy('id');
        $this->assertFalse($orders[$bare]['has_prescription']);
        $this->assertFalse($orders[$bare]['has_frame_measures']);
        $this->assertTrue($orders[$done]['has_prescription']);
        $this->assertTrue($orders[$done]['has_frame_measures']);
    }

    /** Óculos de ficha ainda não lançada vai ao laboratório (18/11/2026); a ficha se lança depois, com a entrada. */
    public function test_glasses_of_a_draft_sale_go_to_the_lab_and_the_sale_is_launched_later(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::Admin)->create());
        $this->putJson('/api/admin/sale-settings', ['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]])->assertOk();
        $sale = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 500))->assertCreated();

        $this->postJson("/api/admin/lab-order-visits/{$this->visit->id}/send")->assertOk()->assertJsonPath('data.sent', 1);
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.orders.0.sent', true);

        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'pix', 'amount' => 200]]])
            ->assertOk()->assertJsonPath('data.status', 'launched')->assertJsonPath('data.paid', '200.00');
    }

    public function test_a_payer_without_cpf_launches_the_sale(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::Admin)->create());
        $this->company->update(['sale_accounts' => ['cash' => 1, 'pix' => 2, 'card' => 2]]);
        $this->mother->update(['cpf' => null]);
        $sale = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 500))->assertCreated();

        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'cash', 'amount' => 100]]])->assertOk();
        $this->assertNull($this->financial[0]['body']['customer']['document']);
        $this->assertSame('Luiza da Silva Sousa', $this->financial[0]['body']['customer']['name']);
    }

    public function test_store_sale_rules_and_cancel(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::Admin)->create());
        $this->company->update(['sale_accounts' => ['cash' => 1, 'pix' => 2, 'card' => 2]]);

        // Venda da loja (sem atendimento): o óculos é avulso.
        $sale = $this->newSale(['city_visit_id' => null]);
        $number = $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->child, 450, ['service_place' => 'internal', 'city_visit_id' => null]))->assertCreated()->json('data.number');
        $this->getJson('/api/admin/sales?standalone=1')->assertJsonCount(1, 'data');
        // Busca também pelos óculos: o paciente e a OS de Ótica.
        $this->getJson('/api/admin/sales?search=talisson')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sale);
        $this->getJson("/api/admin/sales?search={$number}")->assertJsonPath('data.0.id', $sale);
        $this->getJson('/api/admin/sales?search=ninguem')->assertJsonCount(0, 'data');

        // Cliente que paga sem telefone não lança (sem CPF lança: 14/11/2026).
        $this->patchJson("/api/admin/sales/{$sale}", ['client_id' => $this->child->id, 'expected_delivery_date' => today()->addDays(10)->toDateString()])->assertOk();
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => []])->assertJsonValidationErrors(['client_id' => 'telefone']);
        $this->patchJson("/api/admin/sales/{$sale}", ['client_id' => $this->mother->id, 'expected_delivery_date' => today()->addDays(10)->toDateString()])->assertOk();
        // Quitou tudo na hora: lança sem restante.
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => [['method' => 'cash', 'amount' => 450]]])->assertOk()->assertJsonPath('data.remaining', '0.00');
        $this->getJson('/api/admin/sales?search=luiza')->assertJsonCount(1, 'data');

        // Cancelar (admin e, desde 12/11/2026, o atendente; antes de enviar): os óculos pendentes também e o título no Financeiro.
        $this->financial = [];
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->postJson("/api/admin/sales/{$sale}/cancel", ['reason' => 'Desistiu'])->assertOk()->assertJsonPath('data.status', 'canceled')
            ->assertJsonPath('data.orders.0.status', 'canceled')->assertJsonPath('data.total', '0.00');
        $this->assertSame("/api/integrations/optica/sales/{$sale}/cancel", $this->financial[0]['url']);
    }

    /** Notificação de entregas (21/11/2026): os clientes das fichas lançadas, o parecer e quem já foi avisado. */
    public function test_delivery_notices_list_launched_sales_and_mark_who_was_told(): void
    {
        $agent = User::factory()->team(UserRole::FieldAgent)->create(['name' => 'Ana']);
        Passport::actingAs($agent);
        $this->company->update(['sale_accounts' => ['pix' => 2, 'card' => 2, 'cash' => 1]]);
        $launched = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($launched, $this->child, 600))->assertCreated();
        $this->postJson("/api/admin/sales/{$launched}/launch", ['payments' => [['method' => 'pix', 'amount' => 200]]])->assertOk();
        LabOrder::where('sale_id', $launched)->update(['status' => 'ready']);
        $this->newSale(); // rascunho: não entra

        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}/delivery-notices")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $launched)
            ->assertJsonPath('data.0.client.name', 'Luiza da Silva Sousa')
            // Atendente de campo não vê o número: só o mascarado (o WhatsApp abre pelo botão).
            ->assertJsonPath('data.0.client.phone_number', null)
            ->assertJsonPath('data.0.client.has_phone', true)
            ->assertJsonPath('data.0.glasses', 1)
            ->assertJsonPath('data.0.glasses_ready', 1)
            ->assertJsonPath('data.0.notified', false);

        $this->putJson("/api/admin/sales/{$launched}/delivery-notice", ['notified' => true, 'notice' => ' Vem buscar sábado '])->assertOk()
            ->assertJsonPath('data.notified', true)->assertJsonPath('data.notified_by', 'Ana')->assertJsonPath('data.notice', 'Vem buscar sábado');
        // Só o parecer muda: continua avisado por quem avisou primeiro.
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create(['name' => 'Bia']));
        $this->putJson("/api/admin/sales/{$launched}/delivery-notice", ['notified' => true, 'notice' => 'Remarcou para segunda'])->assertOk()
            ->assertJsonPath('data.notified_by', 'Ana')->assertJsonPath('data.notice', 'Remarcou para segunda');
        $this->putJson("/api/admin/sales/{$launched}/delivery-notice", ['notified' => false, 'notice' => 'Remarcou para segunda'])->assertOk()
            ->assertJsonPath('data.notified', false)->assertJsonPath('data.notified_by', null);
        $this->putJson("/api/admin/sales/{$launched}/delivery-notice", ['notice' => 'x'])->assertJsonValidationErrors(['notified']);
    }

    /** Upsell (21/11/2026): tratamentos do catálogo e limpa lentes, com quem vendeu; o relatório do atendimento. */
    public function test_upsell_is_saved_per_glasses_and_listed_in_the_visit_report(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $sale = $this->newSale();
        $order = $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->child, 600))->assertCreated()->json('data.id');
        $other = $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 300))->assertCreated()->json('data.id');

        $this->putJson("/api/admin/lab-orders/{$order}/upsell", ['treatment_ids' => [7], 'lens_cleaner' => 2])->assertJsonValidationErrors(['seller']);
        $this->putJson("/api/admin/lab-orders/{$order}/upsell", ['treatment_ids' => [99], 'lens_cleaner' => 0, 'seller' => 'Carla'])->assertJsonValidationErrors(['treatment_ids']);
        $this->putJson("/api/admin/lab-orders/{$order}/upsell", ['treatment_ids' => [7], 'lens_cleaner' => 2, 'seller' => ' Carla '])->assertOk()
            ->assertJsonPath('data.upsell.treatments', [['id' => 7, 'name' => 'Antirreflexo']])->assertJsonPath('data.upsell.seller', 'Carla');
        $this->putJson("/api/admin/lab-orders/{$other}/upsell", ['treatment_ids' => [], 'lens_cleaner' => 1, 'seller' => 'carla'])->assertOk();
        // O total da ficha não muda.
        $this->getJson("/api/admin/sales/{$sale}")->assertJsonPath('data.total', '900.00')->assertJsonPath('data.orders.0.upsell.lens_cleaner', 2);

        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}/upsells")->assertOk()
            ->assertJsonCount(2, 'data.upsells')
            ->assertJsonPath('data.upsells.0.patient', 'Pedro Talisson Silva Santos')
            ->assertJsonPath('data.upsells.0.buyer', 'Luiza da Silva Sousa')
            ->assertJsonPath('data.upsells.0.treatments', ['Antirreflexo'])
            ->assertJsonCount(1, 'data.sellers')
            ->assertJsonPath('data.sellers.0.glasses', 2)
            ->assertJsonPath('data.sellers.0.treatments', 1)
            ->assertJsonPath('data.sellers.0.lens_cleaner', 3);
        // Relatório de entregas (24/11/2026): os limpa lentes de cada óculos, para levar junto.
        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}/sales-report")->assertJsonPath('data.sales.0.orders.0.lens_cleaner', 2)->assertJsonPath('data.sales.0.orders.1.lens_cleaner', 1);

        // Nada marcado: apaga o upsell.
        $this->putJson("/api/admin/lab-orders/{$other}/upsell", ['treatment_ids' => [], 'lens_cleaner' => 0, 'seller' => null])->assertOk()->assertJsonPath('data.upsell', null);
        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}/upsells")->assertJsonCount(1, 'data.upsells');
    }

    /** Quem indicou e como conheceu (23/11/2026): só os óculos vendidos (ficha lançada), pelo cliente da ficha. */
    public function test_referral_report_lists_sold_glasses_by_the_buyer(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->mother->update(['referred_by' => 'Dona Maria', 'referral_source' => 'partner']);
        $neighbor = Client::create(['name' => 'Ana Vizinha', 'phone_number' => '88999990000', 'address' => 'Rua A', 'city_id' => $this->city->id, 'referred_by' => ' dona maria ', 'referral_source' => 'instagram']);

        $sale = $this->newSale();
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->child, 600))->assertCreated();
        $this->postJson('/api/admin/lab-orders', $this->glasses($sale, $this->mother, 300))->assertCreated();
        $this->postJson("/api/admin/sales/{$sale}/launch", ['payments' => []])->assertOk();
        $other = $this->newSale(['client_id' => $neighbor->id]);
        $this->postJson('/api/admin/lab-orders', $this->glasses($other, $neighbor, 400))->assertCreated();
        $this->postJson("/api/admin/sales/{$other}/launch", ['payments' => []])->assertOk();
        // Rascunho não é venda: fica de fora.
        $draft = $this->newSale(['client_id' => $this->child->id]);
        $this->postJson('/api/admin/lab-orders', $this->glasses($draft, $this->child, 500))->assertCreated();

        $this->getJson("/api/admin/lab-order-visits/{$this->visit->id}/referrals")->assertOk()
            ->assertJsonCount(3, 'data.glasses')
            ->assertJsonPath('data.clients', 2)
            ->assertJsonPath('data.without_referrer', 0)
            ->assertJsonPath('data.glasses.0.buyer.name', 'Luiza da Silva Sousa')
            ->assertJsonPath('data.glasses.0.patient', 'Pedro Talisson Silva Santos')
            ->assertJsonPath('data.glasses.0.referral_source', 'partner')
            ->assertJsonPath('data.sources.0.source', 'instagram')
            ->assertJsonPath('data.sources.0.glasses', 1)
            ->assertJsonPath('data.sources.1.glasses', 2)
            ->assertJsonPath('data.sources.1.clients', 1)
            ->assertJsonCount(1, 'data.referrers')
            ->assertJsonPath('data.referrers.0.name', 'Dona Maria')
            ->assertJsonPath('data.referrers.0.glasses', 3)
            ->assertJsonPath('data.referrers.0.clients', ['Luiza da Silva Sousa', 'Ana Vizinha']);
    }
}
