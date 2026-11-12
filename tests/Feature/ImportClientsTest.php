<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Importação de clientes de planilha (12/11/2026, clientes antigos da Perfeita Visão) e os campos novos do cliente:
 * última compra (data e valor), quem indicou e observações.
 */
class ImportClientsTest extends TestCase
{
    use RefreshDatabase;

    private Company $pv;

    private City $barbalha;

    private City $aracati;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pv = Company::factory()->create(['name' => 'Projeto Perfeita Visão', 'console_id' => '01PERFEITAVISAO0000000000A']);
        $this->barbalha = City::factory()->create(['name' => 'Barbalha']);
        $this->aracati = City::factory()->create(['name' => 'Aracati']);
        $this->pv->cities()->attach([$this->barbalha->id, $this->aracati->id]);
        $this->csv = tempnam(sys_get_temp_dir(), 'clientes');
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    /** @param  list<array<string, string>>  $rows */
    private function writeCsv(array $rows): void
    {
        $columns = ['origem', 'cidade', 'nome', 'telefone', 'endereco', 'data_compra', 'valor', 'indicacao', 'os', 'observacoes', 'cpf', 'nascimento'];
        $out = fopen($this->csv, 'w');
        fputcsv($out, $columns, escape: '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $columns), escape: '');
        }
        fclose($out);
    }

    /** @return Collection<int, Client> */
    private function pvClients()
    {
        return Client::withoutGlobalScopes()->where('company_id', $this->pv->id)->orderBy('name')->get();
    }

    public function test_imports_with_the_agreed_rules(): void
    {
        $this->writeCsv([
            // Responsável entre parênteses: vira o cliente; o de fora é o paciente.
            ['origem' => 'L!2', 'cidade' => 'Aracati', 'nome' => 'Ana Mikaelle Ferreira (Nayara Lima)', 'telefone' => '(88) 9 9466-9561', 'data_compra' => '2024-07-22', 'valor' => '450.00', 'indicacao' => 'Altaci'],
            // Anotação e apelido: o de fora continua sendo o cliente. Telefone sem DDD.
            ['origem' => 'L!3', 'cidade' => 'barbalha', 'nome' => 'Fco Bergson da Luz (retinografia)', 'telefone' => '9948-8453', 'data_compra' => '2025-01-10', 'valor' => '300'],
            ['origem' => 'L!4', 'cidade' => 'Barbalha', 'nome' => 'Maria José Souza  Silva (Mazé)', 'telefone' => '', 'os' => '2081', 'observacoes' => 'Óculos para Nicole'],
            // Mesmo nome na mesma cidade (sem acento): um cliente só, com a compra mais recente.
            ['origem' => 'L!5', 'cidade' => 'Barbalha', 'nome' => 'José Pereira', 'telefone' => '88999990001', 'data_compra' => '2023-03-01', 'valor' => '400', 'indicacao' => 'Loja', 'endereco' => 'Rua A'],
            ['origem' => 'L!6', 'cidade' => 'Barbalha', 'nome' => 'Jose  pereira', 'telefone' => '', 'data_compra' => '2025-05-02', 'valor' => '600', 'indicacao' => 'Sandra', 'os' => '10'],
            // A mesma compra nas duas abas (mesma O.S.) não soma; CPF e nascimento vêm da aba Clientes.
            ['origem' => 'L!7', 'cidade' => 'Aracati', 'nome' => 'Antonio Gonçalves', 'telefone' => '88994529577', 'data_compra' => '2024-07-19', 'valor' => '450', 'os' => '4831'],
            ['origem' => 'C!3', 'cidade' => 'Aracati', 'nome' => 'Antônio Gonçalves', 'telefone' => '88994529577', 'data_compra' => '2024-07-19', 'valor' => '450', 'os' => '4831', 'cpf' => '064.103.073-81', 'nascimento' => '1978-02-21'],
            // Cidade que a ótica não atende: fica de fora, no relatório.
            ['origem' => 'L!8', 'cidade' => 'Forquilha', 'nome' => 'Fulano'],
        ]);

        $this->artisan('clients:import', ['file' => $this->csv, '--company' => $this->pv->console_id, '--dry-run' => true])
            ->expectsOutputToContain('Simulação')->assertSuccessful();
        $this->assertCount(0, $this->pvClients());

        $this->artisan('clients:import', ['file' => $this->csv, '--company' => $this->pv->console_id])->assertSuccessful();
        $clients = $this->pvClients()->keyBy('name');
        $this->assertSame(['Antônio Gonçalves', 'Fco Bergson da Luz', 'José Pereira', 'Maria José Souza Silva', 'Nayara Lima'], $clients->keys()->all());

        $nayara = $clients['Nayara Lima'];
        $this->assertSame($this->aracati->id, $nayara->city_id);
        $this->assertSame('88994669561', $nayara->phone_number);
        $this->assertSame('2024-07-22', $nayara->last_purchase_date->toDateString());
        $this->assertSame('450.00', $nayara->last_purchase_amount);
        $this->assertSame('Altaci', $nayara->referred_by);
        $this->assertSame('Paciente: Ana Mikaelle Ferreira', $nayara->notes);
        $this->assertNull($nayara->birth_date);

        $this->assertSame('88999488453', $clients['Fco Bergson da Luz']->phone_number);
        $this->assertSame('retinografia', $clients['Fco Bergson da Luz']->notes);
        $this->assertSame("Apelido: Mazé · O.S. 2081 · Óculos para Nicole\nSem telefone", $clients['Maria José Souza Silva']->notes);

        $jose = $clients['José Pereira'];
        $this->assertSame('2025-05-02', $jose->last_purchase_date->toDateString());
        $this->assertSame('600.00', $jose->last_purchase_amount);
        $this->assertSame('88999990001', $jose->phone_number);
        $this->assertSame('Rua A', $jose->address);
        $this->assertSame('Sandra, Loja', $jose->referred_by);
        $this->assertStringContainsString('Compra de 02/05/2025 (R$ 600,00) · O.S. 10', $jose->notes);
        $this->assertStringContainsString('Compra de 01/03/2023 (R$ 400,00)', $jose->notes);

        $antonio = $clients['Antônio Gonçalves'];
        $this->assertSame('450.00', $antonio->last_purchase_amount);
        $this->assertSame('06410307381', $antonio->cpf);
        $this->assertSame('1978-02-21', $antonio->birth_date->toDateString());

        // A outra ótica não recebe nada; rodar de novo não duplica.
        $this->assertSame(0, Client::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->artisan('clients:import', ['file' => $this->csv, '--company' => $this->pv->console_id])->assertSuccessful();
        $this->assertCount(5, $this->pvClients());
    }

    public function test_requires_a_known_company(): void
    {
        $this->writeCsv([['cidade' => 'Barbalha', 'nome' => 'Fulano']]);
        $this->artisan('clients:import', ['file' => $this->csv])->assertFailed();
        $this->artisan('clients:import', ['file' => $this->csv, '--company' => 'nao-existe'])->assertFailed();
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public function test_client_form_saves_the_new_fields(): void
    {
        $city = City::factory()->create();
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $base = ['name' => 'Maria', 'city_id' => $city->id, 'birth_date' => '1990-05-01'];

        $id = $this->postJson('/api/admin/clients', [...$base, 'last_purchase_date' => '2024-07-22', 'last_purchase_amount' => 450.5, 'referred_by' => 'Ana Paula', 'notes' => 'Paciente: João'])
            ->assertCreated()
            ->assertJsonPath('data.last_purchase_date', '2024-07-22')
            ->assertJsonPath('data.last_purchase_amount', 450.5)
            ->assertJsonPath('data.referred_by', 'Ana Paula')
            ->assertJsonPath('data.notes', 'Paciente: João')
            ->json('data.id');

        $this->patchJson("/api/admin/clients/{$id}", [...$base, 'last_purchase_date' => null, 'last_purchase_amount' => null, 'referred_by' => null, 'notes' => null])
            ->assertOk()->assertJsonPath('data.last_purchase_date', null)->assertJsonPath('data.notes', null);

        $this->postJson('/api/admin/clients', [...$base, 'last_purchase_date' => today()->addDay()->toDateString(), 'last_purchase_amount' => -1])
            ->assertJsonValidationErrors(['last_purchase_date', 'last_purchase_amount']);
        $this->assertSame(1, app(CurrentCompany::class)->within($this->company, fn () => Client::count()));
    }
}
