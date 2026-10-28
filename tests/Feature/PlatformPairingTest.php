<?php

namespace Tests\Feature;

use App\Models\PlatformAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pareamento com o Console pela tela Módulos de lá (a chave fica no banco, nunca no `.env`).
 */
class PlatformPairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_pairs_once_and_then_rotates_the_key(): void
    {
        $first = str_repeat('a', 64);
        $second = str_repeat('b', 64);

        $this->getJson('/api/platform/pair')->assertOk()->assertJson(['data' => ['module' => 'optica', 'paired' => false]]);
        $this->withToken($first)->getJson('/api/platform/ping')->assertStatus(503);
        $this->postJson('/api/platform/pair', ['token' => 'curta'])->assertUnprocessable();

        $this->postJson('/api/platform/pair', ['token' => $first])->assertCreated()->assertJsonPath('data.module', 'optica');
        $this->assertNotSame($first, PlatformAccess::current()->token_hash);
        $this->getJson('/api/platform/pair')->assertOk()->assertJsonPath('data.paired', true);
        // Depois de pareado, ninguém mais pareia.
        $this->postJson('/api/platform/pair', ['token' => $second])->assertStatus(409);

        $this->withToken($second)->getJson('/api/platform/ping')->assertUnauthorized();
        $this->withToken($first)->getJson('/api/platform/ping')->assertOk()->assertJsonPath('data.module', 'optica');
        $this->withToken($first)->putJson('/api/platform/token', ['token' => $second])->assertOk();
        $this->withToken($first)->getJson('/api/platform/ping')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/platform/ping')->assertOk();
        $this->assertSame(1, PlatformAccess::count());
    }
}
