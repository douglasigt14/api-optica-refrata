<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\PlatformAccess;
use App\Models\User;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Usuários da equipe vindos do Console (passo 7 do roteiro): criar, atualizar, tirar o acesso e importar.
 */
class PlatformUserTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '01JD00000000000000000USER1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PassportClientSeeder::class);
        PlatformAccess::create(['token_hash' => PlatformAccess::hash('token-da-plataforma'), 'paired_at' => now()]);
        $this->company->group->update(['console_id' => '01JD0000000000000000GROUP1']);
        $this->company->update(['console_id' => '01JD000000000000000000OTIC']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sendUser(array $overrides = [], string $id = self::USER): TestResponse
    {
        return $this->withToken('token-da-plataforma')->putJson("/api/platform/users/{$id}", [
            'group' => ['console_id' => '01JD0000000000000000GROUP1'],
            'name' => 'Maria',
            'email' => 'Maria@Royal.com',
            'password_hash' => Hash::make('segredo123'),
            'companies' => [['console_id' => '01JD000000000000000000OTIC', 'roles' => ['field_agent']]],
            ...$overrides,
        ]);
    }

    public function test_creates_updates_and_takes_the_access_without_deleting(): void
    {
        $this->sendUser()->assertOk()->assertJsonPath('data.exists', true);
        $user = User::firstWhere('console_id', self::USER);
        $this->assertSame(UserRole::FieldAgent, $user->roleIn($this->company));
        $this->postJson('/api/admin/login', ['email' => 'maria@royal.com', 'password' => 'segredo123'])->assertOk();

        $this->sendUser(['password_hash' => null, 'companies' => [['console_id' => '01JD000000000000000000OTIC', 'roles' => ['admin']]]])->assertOk();
        $this->assertSame(UserRole::Admin, $user->fresh()->roleIn($this->company));

        $this->sendUser(['companies' => []])->assertOk();
        $this->assertTrue(User::where('console_id', self::USER)->exists());
        $this->postJson('/api/admin/login', ['email' => 'maria@royal.com', 'password' => 'segredo123'])->assertUnprocessable();
    }

    public function test_links_by_email_once_and_refuses_partners_and_two_roles(): void
    {
        $old = User::factory()->team()->create(['email' => 'maria@royal.com']);
        $this->sendUser(['password_hash' => null])->assertOk();
        $this->assertSame(self::USER, $old->fresh()->console_id);

        $this->sendUser(['email' => 'nunca@royal.com', 'companies' => []], '01JD00000000000000000USER2')->assertOk()->assertJsonPath('data.exists', false);
        User::factory()->create(['email' => 'parceiro@royal.com']);
        $this->sendUser(['email' => 'parceiro@royal.com'], '01JD00000000000000000USER3')->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->sendUser(['email' => 'dois@royal.com', 'companies' => [['console_id' => '01JD000000000000000000OTIC', 'roles' => ['admin', 'factory']]]], '01JD00000000000000000USER4')
            ->assertUnprocessable()->assertJsonValidationErrors(['companies.0.roles']);
        $this->sendUser(['email' => 'outro@royal.com', 'group' => ['console_id' => '01JD0000000000000000GROUP2']], '01JD00000000000000000USER5')->assertUnprocessable()->assertJsonValidationErrors(['group']);
    }

    public function test_lists_the_team_without_partners_for_the_import(): void
    {
        User::factory()->team(UserRole::Factory)->create(['name' => 'Carla', 'email' => 'carla@royal.com']);
        User::factory()->create();
        User::factory()->create(['group_id' => Group::create(['name' => 'Outro'])->id, 'prospector_id' => null, 'phone_number' => null, 'email' => 'x@outro.com']);

        $this->withToken('token-da-plataforma')->getJson('/api/platform/groups/01JD0000000000000000GROUP1/users')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.companies.0', ['console_id' => '01JD000000000000000000OTIC', 'roles' => ['factory']]);
    }

    public function test_gives_the_person_a_token_for_the_single_login(): void
    {
        $url = '/api/platform/users/'.self::USER.'/token';
        $this->withToken('outra-chave')->postJson($url)->assertUnauthorized();
        $this->withToken('token-da-plataforma')->postJson($url)->assertNotFound();

        $this->sendUser()->assertOk();
        $this->withToken('token-da-plataforma')->postJson($url, ['device_name' => 'app.refrataopto'])
            ->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('user.email', 'maria@royal.com');

        // Sem acesso (o Console tirou as empresas): não ganha token. E o que já tinha deixa de valer, como no login daqui.
        $this->sendUser(['companies' => [], 'password_hash' => null])->assertOk();
        $this->withToken('token-da-plataforma')->postJson($url)->assertUnprocessable();

        // Com acesso de novo, o token vale como o do login daqui (por último: depois de entrar com ele, o teste não
        // troca mais o token do cabeçalho).
        $this->sendUser(['password_hash' => null])->assertOk();
        $token = $this->withToken('token-da-plataforma')->postJson($url)->assertOk()->json('access_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/me')->assertOk()->assertJsonFragment(['email' => 'maria@royal.com']);
    }
}
