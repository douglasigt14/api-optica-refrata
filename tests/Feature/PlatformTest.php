<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Group;
use App\Models\LeadStatus;
use App\Models\PlatformAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Console cria e atualiza as óticas (e o grupo delas).
 */
class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '01JD0000000000000000GROUP1';

    protected function setUp(): void
    {
        parent::setUp();
        PlatformAccess::create(['token_hash' => PlatformAccess::hash('token-da-plataforma'), 'paired_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'group' => ['console_id' => self::GROUP, 'name' => 'Grupo Royal', 'blocked' => false],
            'name' => 'Otica Exclusive Ltda',
            'trade_name' => 'Otica Exclusive',
            'document' => '34710587000144',
            'active' => true,
            ...$overrides,
        ];
    }

    public function test_links_the_optica_that_already_existed_by_cnpj(): void
    {
        $this->company->update(['document' => '34710587000144']);
        // Nome do grupo diferente do Console: não importa (liga pelo CNPJ da ótica, nunca pelo nome).
        $this->company->group->update(['name' => 'Nome antigo do grupo']);

        $this->putJson('/api/platform/companies/01JD000000000000000000EXCL', $this->payload())->assertUnauthorized();
        $this->withToken('token-da-plataforma')->putJson('/api/platform/companies/01JD000000000000000000EXCL', $this->payload())
            ->assertOk()->assertJsonPath('data.id', $this->company->id)->assertJsonPath('data.invite_code', 'EXCLUSIVE');

        $this->assertSame('01JD000000000000000000EXCL', $this->company->fresh()->console_id);
        $this->assertSame([self::GROUP, 'Grupo Royal'], [$this->company->group->fresh()->console_id, $this->company->group->fresh()->name]);
        // Daí em diante, pelo ID: o nome do grupo muda no Console e acompanha.
        $this->withToken('token-da-plataforma')->putJson('/api/platform/companies/01JD000000000000000000EXCL', $this->payload(['group' => ['console_id' => self::GROUP, 'name' => 'Grupo Complexo Visual', 'blocked' => false]]))->assertOk();
        $this->assertSame('Grupo Complexo Visual', $this->company->group->fresh()->name);
        $this->assertSame([1, 1], [Company::count(), Group::count()]);
    }

    public function test_new_optica_gets_invite_code_and_default_statuses(): void
    {
        $this->withToken('token-da-plataforma')
            ->putJson('/api/platform/companies/01JD0000000000000000NEWOTI', $this->payload([
                'document' => '11222333000181', 'name' => 'Ótica Bem Estar Ltda', 'trade_name' => 'Ótica Bem Estar',
                'group' => ['console_id' => '01JD0000000000000000GROUP2', 'name' => 'Grupo X', 'blocked' => false],
            ]))
            ->assertCreated()->assertJsonPath('data.invite_code', 'BEMESTAR');

        $company = Company::firstWhere('console_id', '01JD0000000000000000NEWOTI');
        $this->assertSame(6, LeadStatus::withoutGlobalScopes()->where('company_id', $company->id)->where('is_default', true)->count());
        $this->assertSame('BEMESTAR2', Company::inviteCodeFor('Bem Estar'));
    }
}
