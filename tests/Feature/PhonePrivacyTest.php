<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PhoneReveal;
use App\Models\User;
use App\Support\PhonePrivacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Sigilo do telefone (CRM da Ótica, 12/11/2026): só o admin vê o número dos clientes e das indicações; os outros recebem o
 * mascarado, não buscam pelo número e só podem trocá-lo por outro.
 */
class PhonePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = City::factory()->create();
        $this->client = Client::create(['name' => 'Nayara Lima', 'city_id' => $this->city->id, 'phone_number' => '88994669561']);
    }

    public function test_masks(): void
    {
        $this->assertSame('(88) 9••••-••61', PhonePrivacy::mask('88994669561'));
        $this->assertSame('(88) ••••-••56', PhonePrivacy::mask('8899466956'));
        $this->assertNull(PhonePrivacy::mask(null));
        $this->assertNull(PhonePrivacy::mask(''));
    }

    public function test_only_the_admin_sees_the_client_phone(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->getJson("/api/admin/clients/{$this->client->id}")->assertOk()
            ->assertJsonPath('data.phone_number', null)
            ->assertJsonPath('data.phone_masked', '(88) 9••••-••61')
            ->assertJsonPath('data.has_phone', true);
        $this->assertStringNotContainsString('94669561', $this->getJson('/api/admin/clients')->getContent());
        // Não acha pelo número; pelo nome, sim.
        $this->getJson('/api/admin/clients?search=4669561')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/clients?search=nayara')->assertJsonCount(1, 'data');

        Passport::actingAs(User::factory()->team(UserRole::Admin)->create());
        $this->getJson("/api/admin/clients/{$this->client->id}")->assertJsonPath('data.phone_number', '88994669561');
        $this->getJson('/api/admin/clients?search=4669561')->assertJsonCount(1, 'data');
    }

    public function test_the_attendant_replaces_but_does_not_erase_the_phone(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $base = ['name' => 'Nayara Lima', 'city_id' => $this->city->id, 'birth_date' => '1990-01-01'];

        $this->patchJson("/api/admin/clients/{$this->client->id}", [...$base, 'phone_number' => null])->assertOk();
        $this->assertSame('88994669561', $this->client->fresh()->phone_number);
        $this->patchJson("/api/admin/clients/{$this->client->id}", $base)->assertOk();
        $this->assertSame('88994669561', $this->client->fresh()->phone_number);

        $this->patchJson("/api/admin/clients/{$this->client->id}", [...$base, 'phone_number' => '(88) 99999-0000'])
            ->assertOk()->assertJsonPath('data.phone_number', null)->assertJsonPath('data.phone_masked', '(88) 9••••-••00');
        $this->assertSame('88999990000', $this->client->fresh()->phone_number);
    }

    public function test_only_the_admin_sees_the_lead_phone(): void
    {
        $lead = Lead::factory()->create(['city_id' => $this->city->id, 'name' => 'Maria', 'phone_number' => '88912345678']);

        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->getJson("/api/admin/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('data.phone_number', null)
            ->assertJsonPath('data.phone_masked', '(88) 9••••-••78');
        $this->getJson('/api/admin/leads?search=12345678')->assertJsonCount(0, 'data');

        Passport::actingAs(User::factory()->team(UserRole::Admin)->create());
        $this->getJson("/api/admin/leads/{$lead->id}")->assertJsonPath('data.phone_number', '88912345678');
        $this->getJson('/api/admin/leads?search=12345678')->assertJsonCount(1, 'data');
    }

    /** "Ligar pelo WhatsApp": todos abrem, o número sai só no clique e fica registrado quem abriu. */
    public function test_whatsapp_link_is_logged(): void
    {
        $agent = User::factory()->team(UserRole::FieldAgent)->create();
        Passport::actingAs($agent);
        $this->postJson("/api/admin/clients/{$this->client->id}/whatsapp-link")->assertOk()->assertJsonPath('data.url', 'https://wa.me/5588994669561');
        $this->assertDatabaseHas('phone_reveals', ['user_id' => $agent->id, 'subject_type' => Client::class, 'subject_id' => $this->client->id, 'company_id' => $this->company->id]);

        $lead = Lead::factory()->create(['city_id' => $this->city->id, 'phone_number' => '88912345678']);
        $this->postJson("/api/admin/leads/{$lead->id}/whatsapp-link")->assertJsonPath('data.url', 'https://wa.me/5588912345678');

        $none = Client::create(['name' => 'Sem telefone', 'city_id' => $this->city->id]);
        $this->postJson("/api/admin/clients/{$none->id}/whatsapp-link")->assertJsonValidationErrors(['phone_number']);
        $this->assertSame(2, PhoneReveal::count());
    }
}
