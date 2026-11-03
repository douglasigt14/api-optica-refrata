<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuários dos sistemas pelo Console (passo 7 do roteiro, 03/11/2026): o usuário criado ou importado pelo Console guarda
 * o ID de lá (`console_id`); a ligação é só por ele (o e-mail liga uma vez, na importação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('console_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['console_id']);
            $table->dropColumn('console_id');
        });
    }
};
