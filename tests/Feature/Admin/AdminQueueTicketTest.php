<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\CityVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** Senhas da fila do atendimento (12/11/2026). */
class AdminQueueTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_tickets_count_per_type_and_per_visit(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $visit = CityVisit::factory()->create();
        $other = CityVisit::factory()->create();
        $url = "/api/admin/lab-order-visits/{$visit->id}/tickets";

        $this->postJson($url, ['type' => 'normal'])->assertCreated()->assertJsonPath('data.code', 'N001');
        $this->postJson($url, ['type' => 'normal'])->assertCreated()->assertJsonPath('data.code', 'N002');
        $this->postJson($url, ['type' => 'priority'])->assertCreated()->assertJsonPath('data.code', 'P001');
        $this->postJson($url, ['type' => 'vip'])->assertJsonValidationErrors(['type']);
        // Outro atendimento começa do 1.
        $this->postJson("/api/admin/lab-order-visits/{$other->id}/tickets", ['type' => 'normal'])->assertJsonPath('data.code', 'N001');

        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.issued.normal', 2)
            ->assertJsonPath('data.issued.priority', 1)
            ->assertJsonPath('data.waiting.0.code', 'P001')
            ->assertJsonPath('data.waiting.1.code', 'N001');
    }

    public function test_call_next_puts_priority_first_and_can_call_again(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $visit = CityVisit::factory()->create();
        $url = "/api/admin/lab-order-visits/{$visit->id}/tickets";

        $this->postJson("$url/call-next")->assertJsonValidationErrors(['ticket']);
        $n1 = $this->postJson($url, ['type' => 'normal'])->json('data.id');
        $this->postJson($url, ['type' => 'normal']);
        $this->postJson($url, ['type' => 'priority']);

        $this->postJson("$url/call-next")->assertOk()->assertJsonPath('data.code', 'P001');
        $this->travel(5)->seconds();
        $this->postJson("$url/call-next")->assertOk()->assertJsonPath('data.code', 'N001');
        $this->getJson($url)->assertJsonPath('data.current.code', 'N001')->assertJsonCount(1, 'data.waiting')->assertJsonCount(2, 'data.called');

        // Chamar de novo uma escolhida; de outro atendimento, 404.
        $this->travel(1)->minutes();
        $this->postJson("$url/{$n1}/call")->assertOk();
        $this->getJson($url)->assertJsonPath('data.current.code', 'N001');
        $other = CityVisit::factory()->create();
        $this->postJson("/api/admin/lab-order-visits/{$other->id}/tickets/{$n1}/call")->assertNotFound();
    }

    public function test_call_next_of_one_queue(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $visit = CityVisit::factory()->create();
        $url = "/api/admin/lab-order-visits/{$visit->id}/tickets";

        $this->postJson($url, ['type' => 'normal']);
        $this->postJson($url, ['type' => 'priority']);

        $this->postJson("$url/call-next", ['type' => 'normal'])->assertOk()->assertJsonPath('data.code', 'N001');
        $this->postJson("$url/call-next", ['type' => 'normal'])->assertJsonValidationErrors(['ticket']);
        $this->postJson("$url/call-next", ['type' => 'priority'])->assertOk()->assertJsonPath('data.code', 'P001');
        $this->postJson("$url/call-next", ['type' => 'vip'])->assertJsonValidationErrors(['type']);
    }

    public function test_saves_the_printed_number_only_if_it_is_still_free(): void
    {
        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $visit = CityVisit::factory()->create();
        $url = "/api/admin/lab-order-visits/{$visit->id}/tickets";

        $this->getJson($url)->assertJsonPath('data.next_number.normal', 1)->assertJsonPath('data.next_number.priority', 1);
        $this->postJson($url, ['type' => 'normal', 'number' => 1])->assertCreated()->assertJsonPath('data.code', 'N001');
        // Outro computador imprimiu a N001 também: recusa e diz a próxima livre.
        $this->postJson($url, ['type' => 'normal', 'number' => 1])->assertJsonValidationErrors(['number'])
            ->assertJsonPath('errors.number.0', 'A senha N001 já foi emitida em outro computador. Imprima de novo: a próxima é N002.');
        $this->getJson($url)->assertJsonPath('data.next_number.normal', 2)->assertJsonPath('data.issued.normal', 1);
    }

    public function test_only_admin_resets_the_queue_back_to_001(): void
    {
        $visit = CityVisit::factory()->create();
        $other = CityVisit::factory()->create();
        $url = "/api/admin/lab-order-visits/{$visit->id}/tickets";

        Passport::actingAs(User::factory()->team(UserRole::FieldAgent)->create());
        $this->postJson($url, ['type' => 'normal']);
        $this->postJson($url, ['type' => 'priority']);
        $this->postJson("/api/admin/lab-order-visits/{$other->id}/tickets", ['type' => 'normal']);
        $this->deleteJson($url)->assertForbidden();

        Passport::actingAs(User::factory()->team()->create());
        $this->deleteJson($url)->assertOk()->assertJsonPath('data.issued.normal', 0)->assertJsonPath('data.next_number.priority', 1);
        $this->postJson($url, ['type' => 'normal'])->assertJsonPath('data.code', 'N001');
        // O outro atendimento não muda.
        $this->getJson("/api/admin/lab-order-visits/{$other->id}/tickets")->assertJsonPath('data.issued.normal', 1);
    }
}
