<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passo 6 do roteiro (financeiro junto): o endereço do painel do financeiro, mandado pelo Console quando a ótica usa o
 * Financeiro (vazio = sem). O administrador vê o item Financeiro no menu, que abre lá já nesta ótica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('financial_url')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('financial_url');
        });
    }
};
