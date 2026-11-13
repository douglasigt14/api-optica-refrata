<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\CrmEntry;
use App\Models\MessageTemplate;
use App\Models\Prospector;
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
                str_starts_with($path, '/group/findGroupInfos') => Http::response(['id' => '120363000000000001@g.us', 'subject' => 'Família Lima']),
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

    /** Contato sem cadastro vira cliente ou parceiro pela conversa; o telefone vem do WhatsApp e não aparece. */
    public function test_unknown_contact_becomes_a_client_or_a_partner(): void
    {
        $number = $this->number();
        $incoming = fn (string $jid, string $id, string $name = 'cleidysilva igt') => $this->postJson("/api/integration/whatsapp/{$number->id}", ['event' => 'messages.upsert', 'data' => [
            'key' => ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id], 'pushName' => $name, 'message' => ['conversation' => 'Oi'],
        ]], ['X-Webhook-Secret' => $number->webhook_secret])->assertNoContent();
        // Celular sem o 9, como o WhatsApp às vezes manda.
        $incoming('558897771234@s.whatsapp.net', 'IN1');
        $conversation = WhatsAppConversation::firstOrFail();
        $this->assertNull($conversation->client_id);

        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $url = "/api/admin/whatsapp/conversations/{$conversation->id}/register";
        $this->postJson($url, ['name' => ''])->assertJsonValidationErrors(['kind']);
        $this->postJson($url, ['kind' => 'client', 'name' => ''])->assertJsonValidationErrors(['name', 'city_id']);
        $created = $this->postJson($url, ['kind' => 'client', 'name' => 'Cleidy Silva', 'city_id' => $this->client->city_id])->assertCreated()
            ->assertJsonPath('data.name', 'Cleidy Silva');
        $this->assertStringNotContainsString('97771234', $created->getContent());
        $client = Client::where('name', 'Cleidy Silva')->firstOrFail();
        $this->assertSame('88997771234', $client->phone_number);
        $this->assertSame($client->id, $conversation->fresh()->client_id);
        $this->getJson("/api/admin/whatsapp/conversations/{$conversation->id}")->assertJsonPath('data.name', 'Cleidy Silva')
            ->assertJsonPath('data.city', 'Assaré')->assertJsonPath('data.crm.stage', 'to_contact')->assertJsonPath('data.phone_masked', '(88) 9••••-••34');
        $this->postJson($url, ['kind' => 'client', 'name' => 'De novo', 'city_id' => $this->client->city_id])->assertJsonValidationErrors(['person']);

        // Parceiro: como na tela Parceiros (18+, PIX e senha); o telefone vira o login.
        $incoming('5588912345678@s.whatsapp.net', 'IN2', 'Zé');
        $other = WhatsAppConversation::whereNull('client_id')->firstOrFail();
        $url = "/api/admin/whatsapp/conversations/{$other->id}/register";
        $partner = ['kind' => 'partner', 'name' => 'Zé Parceiro', 'city_id' => $this->client->city_id, 'birth_date' => '1990-01-01', 'pix_key' => 'ze@pix', 'password' => 'senha-forte'];
        $this->postJson($url, [...$partner, 'birth_date' => today()->subYears(10)->toDateString(), 'password' => ''])->assertJsonValidationErrors(['birth_date', 'password']);
        $this->postJson($url, $partner)->assertCreated();
        $prospector = Prospector::where('name', 'Zé Parceiro')->firstOrFail();
        $this->assertSame('88912345678', $prospector->phone_number);
        $this->assertSame('88912345678', $prospector->user->phone_number);
        $this->assertSame($prospector->id, $other->fresh()->prospector_id);
        $this->getJson('/api/admin/whatsapp/conversations')->assertJsonPath('data.0.name', 'Zé Parceiro')->assertJsonPath('data.0.prospector_id', $prospector->id);
    }

    /** Grupos aparecem nas Conversas com o nome do grupo e de quem escreveu, sem o número de ninguém. */
    public function test_group_messages_show_up(): void
    {
        $number = $this->number();
        $group = '120363000000000001@g.us';
        $incoming = fn (string $id, string $text) => $this->postJson("/api/integration/whatsapp/{$number->id}", ['event' => 'messages.upsert', 'data' => [
            'key' => ['remoteJid' => $group, 'fromMe' => false, 'id' => $id, 'participant' => '5588994669561@s.whatsapp.net'],
            'pushName' => 'Nay', 'message' => ['conversation' => $text],
        ]], ['X-Webhook-Secret' => $number->webhook_secret])->assertNoContent();
        $incoming('G1', 'Bom dia, grupo');
        $incoming('G2', 'Quando vem a ótica?');
        $this->assertSame(1, collect($this->sent)->filter(fn ($r) => str_contains($r['url'], 'findGroupInfos'))->count());

        $conversation = WhatsAppConversation::firstOrFail();
        $this->assertTrue($conversation->is_group);
        $this->assertNull($conversation->client_id);

        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $list = $this->getJson('/api/admin/whatsapp/conversations')->assertJsonPath('data.0.name', 'Família Lima')->assertJsonPath('data.0.is_group', true)
            ->assertJsonPath('data.0.unread_count', 2)->assertJsonPath('data.0.last_message_preview', 'Nay: Quando vem a ótica?');
        $this->assertStringNotContainsString('94669561', $list->getContent());
        $this->getJson("/api/admin/whatsapp/conversations/{$conversation->id}")->assertJsonPath('data.messages.0.sender_name', 'Nay');

        // Responder no grupo vai pelo identificador do grupo; grupo não vira cliente.
        $this->postJson("/api/admin/whatsapp/conversations/{$conversation->id}/messages", ['text' => 'Dia 16!'])->assertOk();
        $this->assertSame($group, collect($this->sent)->last(fn ($r) => str_contains($r['url'], 'sendText'))['body']['number']);

        // Cadastrar quem escreveu no grupo: precisa dizer a mensagem; a pessoa vira cliente e o grupo continua grupo.
        $url = "/api/admin/whatsapp/conversations/{$conversation->id}/register";
        $stranger = $conversation->messages()->create(['direction' => 'in', 'type' => 'text', 'body' => 'Oi', 'sender_name' => 'Rita', 'sender_jid' => '558897770000@s.whatsapp.net']);
        $this->postJson($url, ['kind' => 'client', 'name' => 'Rita', 'city_id' => $this->client->city_id])->assertJsonValidationErrors(['person']);
        $shown = $this->getJson("/api/admin/whatsapp/conversations/{$conversation->id}");
        $this->assertStringNotContainsString('97770000', $shown->getContent());
        // Nay já é cliente (Nayara Lima); Rita ainda não.
        $shown->assertJsonPath('data.messages.0.sender_registered', 'client')->assertJsonPath('data.messages.0.can_register_sender', false)
            ->assertJsonPath('data.messages.3.can_register_sender', true);
        $this->postJson($url, ['kind' => 'client', 'message_id' => $stranger->id, 'name' => 'Rita Souza', 'city_id' => $this->client->city_id])->assertCreated();
        $this->assertSame('88997770000', Client::where('name', 'Rita Souza')->value('phone_number'));
        $this->assertNull($conversation->fresh()->client_id);
        $this->getJson("/api/admin/whatsapp/conversations/{$conversation->id}")->assertJsonPath('data.messages.3.sender_registered', 'client');

        // Sem nome no WhatsApp, a Evolution manda o número no lugar: não é guardado.
        $this->postJson("/api/integration/whatsapp/{$number->id}", ['event' => 'messages.upsert', 'data' => [
            'key' => ['remoteJid' => $group, 'fromMe' => false, 'id' => 'G3', 'participant' => '5588911112222@s.whatsapp.net'],
            'pushName' => '5588911112222', 'message' => ['conversation' => 'oi'],
        ]], ['X-Webhook-Secret' => $number->webhook_secret])->assertNoContent();
        $this->assertStringNotContainsString('11112222', $this->getJson('/api/admin/whatsapp/conversations')->getContent());
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
