<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A chave com que o Console da Refrata entra neste sistema (`/api/platform`) sai do `.env` e fica no banco (só o hash):
 * ligações entre sistemas nunca em `.env` (admin-refrata/PLANO-PLATAFORMA.md). O pareamento é feito pela tela Módulos
 * do Console. A instalação que já tinha o `PLATFORM_TOKEN` no `.env` continua pareada com ele (rode `config:clear`
 * antes do `migrate`); depois a linha pode sair do `.env`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_access', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64);
            $table->timestamp('paired_at');
            $table->timestamps();
        });

        $token = (string) env('PLATFORM_TOKEN');
        if ($token !== '') {
            DB::table('platform_access')->insert(['token_hash' => hash('sha256', $token), 'paired_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_access');
    }
};
