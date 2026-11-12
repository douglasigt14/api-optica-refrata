<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\CrmEntry;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * WhatsApp do CRM (fase 4, 12/11/2026) pela Evolution (simulada): número com QR Code, conversa com o cliente sem o número
 * aparecer, texto, modelo e áudio, webhook (mensagem nova ligada ao cliente, situação da enviada) e o disparo travado
 * sem a API oficial.
 */
class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{url: string, body: array<string, mixed>, apikey: ?string}> */
    private array $sent = [];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake(function (Request $request) {
            $this->sent[] = ['url' => $request->url(), 'body' => $request->data(), 'apikey' => $request->header('apikey')[0] ?? null];
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_starts_with($path, '/instance/connectionState') => Http::response(['instance' => ['state' => 'close']]),
                str_starts_with($path, '/instance/connect') => Http::response(['base64' => 'data:image/png;base64,QR']),
                str_starts_with($path, '/message/') => Http::response(['key' => ['id' => 'MSG'.count($this->sent)]], 201),
                default => Http::response(['ok' => true], 201),
            };
        });
        $city = City::factory()->create(['name' => 'Assaré']);
        $this->client = Client::create(['name' => 'Nayara Lima', 'city_id' => $city->id, 'phone_number' => '88994669561']);
    }

    private function number(): WhatsAppNumber
    {
        Passport::actingAs(User::factory()->team(UserRole::Admin)->create());
        $id = $this->postJson('/api/admin/whatsapp/numbers', ['name' => 'Atendimento', 'base_url' => 'http://evolution.test', 'api_key' => 'CHAVE'])
            ->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonMissingPath('data.api_key')->json('data.id');

        return WhatsAppNumber::findOrFail($id);
    }

    public function test_admin_adds_a_number_and_gets_the_qr_code(): void
    {
        $number = $this->number();
        $this->assertSame('CHAVE', $number->api_key);
        $create = collect($this->sent)->firstWhere('url', 'http://evolution.test/instance/create');
        $this->assertSame('CHAVE', $create['apikey']);
        $this->assertStringEndsWith("/api/integration/whatsapp/{$number->id}", $create['body']['webhook']['url']);
        $this->assertSame($number->webhook_secret, $create['body']['webhook']['headers']['X-Webhook-Secret']);

        $this->getJson("/api/admin/whatsapp/numbers/{$number->id}/qr")->assertOk()->assertJsonPath('data.qr_code', 'data:image/png;base64,QR')->assertJsonPath('data.status', 'disconnected');

        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->getJson('/api/admin/whatsapp/numbers')->assertForbidden();
    }

    public function test_attendant_chats_without_seeing_the_number(): void
    {
        $this->number();
        $template = MessageTemplate::create(['name' => 'Convite', 'body' => 'Olá, {nome}! Estaremos em {cidade} no dia {data_atendimento}.']);
        $visit = CityVisit::factory()->create(['city_id' => $this->client->city_id, 'visit_date' => '2026-11-16', 'end_date' => '2026-11-16']);
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create(['name' => 'Romana']));

        $conversation = $this->postJson('/api/admin/whatsapp/conversations/start', ['client_id' => $this->client->id])->assertOk()
            ->assertJsonPath('data.name', 'Nayara Lima')->assertJsonPath('data.phone_masked', '(88) 9••••-••61');
        $this->assertStringNotContainsString('94669561', $conversation->getContent());
        $id = $conversation->json('data.id');

        $this->postJson("/api/admin/whatsapp/conversations/{$id}/messages", ['template_id' => $template->id, 'city_visit_id' => $visit->id])
            ->assertOk()->assertJsonPath('data.messages.0.body', 'Olá, Nayara! Estaremos em Assaré no dia 16/11.')->assertJsonPath('data.messages.0.user.name', 'Romana');
        $text = collect($this->sent)->firstWhere('url', 'http://evolution.test/'.'message/sendText/'.WhatsAppNumber::first()->instance);
        $this->assertSame('5588994669561', $text['body']['number']);

        $this->post("/api/admin/whatsapp/conversations/{$id}/audio", ['audio' => UploadedFile::fake()->createWithContent('voz.webm', 'OggS-audio-de-teste')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.messages.1.type', 'audio')->assertJsonPath('data.messages.1.has_media', true);
        $audio = collect($this->sent)->first(fn ($r) => str_contains($r['url'], 'sendWhatsAppAudio'));
        $this->assertNotEmpty($audio['body']['audio']);
    }

    public function test_webhook_stores_incoming_messages_linked_to_the_client(): void
    {
        $number = $this->number();
        $payload = ['event' => 'messages.upsert', 'instance' => $number->instance, 'data' => [
            // O WhatsApp às vezes manda o celular sem o 9.
            'key' => ['remoteJid' => '558894669561@s.whatsapp.net', 'fromMe' => false, 'id' => 'IN1'],
            'pushName' => 'Nay', 'message' => ['conversation' => 'Oi, quero marcar'],
        ]];
        $this->postJson("/api/integration/whatsapp/{$number->id}", $payload)->assertForbidden();
        $this->postJson("/api/integration/whatsapp/{$number->id}", $payload, ['X-Webhook-Secret' => $number->webhook_secret])->assertNoContent();
        $this->postJson("/api/integration/whatsapp/{$number->id}", $payload, ['X-Webhook-Secret' => $number->webhook_secret])->assertNoContent();

        $conversation = WhatsAppConversation::firstOrFail();
        $this->assertSame($this->client->id, $conversation->client_id);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame(1, $conversation->messages()->count());

        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->getJson('/api/admin/whatsapp/conversations')->assertJsonPath('data.0.name', 'Nayara Lima')->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.last_message_preview', 'Oi, quero marcar')
            ->assertJsonPath('data.0.city', 'Assaré')->assertJsonPath('data.0.crm.stage', 'to_contact');
        // Situação atual no CRM: a do atendimento mais recente em que foi trabalhado.
        $visit = CityVisit::factory()->create(['city_id' => $this->client->city_id]);
        CrmEntry::create(['city_visit_id' => $visit->id, 'client_id' => $this->client->id, 'stage' => 'booked']);
        $this->getJson('/api/admin/whatsapp/conversations')->assertJsonPath('data.0.crm.stage_label', 'Marcou')->assertJsonPath('data.0.crm.visit_id', $visit->id);
        $this->getJson("/api/admin/whatsapp/conversations/{$conversation->id}")->assertJsonPath('data.messages.0.direction', 'in');
        $this->assertSame(0, $conversation->fresh()->unread_count);

        // Situação da enviada e da conexão.
        $out = $conversation->messages()->create(['direction' => 'out', 'type' => 'text', 'body' => 'Oi', 'external_id' => 'OUT1']);
        $this->postJson("/api/integration/whatsapp/{$number->id}", ['event' => 'messages.update', 'data' => ['keyId' => 'OUT1', 'status' => 'READ']], ['X-Webhook-Secret' => $number->webhook_secret]);
        $this->assertSame('read', $out->fresh()->status);
        $this->postJson("/api/integration/whatsapp/{$number->id}", ['event' => 'connection.update', 'data' => ['state' => 'open', 'wuid' => '5588999990000@s.whatsapp.net']], ['X-Webhook-Secret' => $number->webhook_secret]);
        $this->assertSame('connected', $number->fresh()->status);
    }

    public function test_broadcast_only_with_the_official_api(): void
    {
        $number = $this->number();
        $template = MessageTemplate::create(['name' => 'Convite', 'body' => 'Olá']);
        $visit = CityVisit::factory()->create(['city_id' => $this->client->city_id]);
        $this->postJson('/api/admin/whatsapp/broadcasts', ['whatsapp_number_id' => $number->id, 'message_template_id' => $template->id, 'city_visit_id' => $visit->id])
            ->assertJsonValidationErrors(['whatsapp_number_id' => 'API oficial']);
        $this->getJson('/api/admin/whatsapp/broadcasts')->assertJsonPath('enabled', false);

        $number->update(['provider' => 'meta_cloud']);
        $this->postJson('/api/admin/whatsapp/broadcasts', ['whatsapp_number_id' => $number->id, 'message_template_id' => $template->id, 'city_visit_id' => $visit->id, 'batch_size' => 20])
            ->assertCreated()->assertJsonPath('data.total', 1)->assertJsonPath('data.status', 'scheduled');
    }
}
