<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendas na Ótica, fase 1 (07/11/2026): o cliente que paga a promissória tem CPF e endereço. O telefone já era opcional
 * no banco (o paciente pode ser outra pessoa, ex.: uma criança).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('cpf', 11)->nullable()->after('name');
            $table->string('address', 200)->nullable()->after('phone_number');
            $table->unique(['company_id', 'cpf']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'cpf']);
            $table->dropColumn(['cpf', 'address']);
        });
    }
};
