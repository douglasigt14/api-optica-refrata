<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos por atendimento (07/11/2026): o atendimento recebe pedidos a partir do 1º dia até alguém clicar "Encerrar
 * pedidos" (`orders_closed_at`). Não depende do "Receber indicações" (`active`): os pedidos chegam dias depois do exame.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('city_visits', function (Blueprint $table) {
            $table->timestamp('orders_closed_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('city_visits', function (Blueprint $table) {
            $table->dropColumn('orders_closed_at');
        });
    }
};
