<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro do cliente (12/11/2026, importação dos clientes da Perfeita Visão): a última compra (data e valor), quem
 * indicou (nome em texto livre, diferente de "como conheceu a ótica") e observações. Tudo opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->date('last_purchase_date')->nullable()->after('wears_glasses');
            $table->decimal('last_purchase_amount', 10, 2)->nullable()->after('last_purchase_date');
            $table->string('referred_by', 120)->nullable()->after('referral_source');
            $table->text('notes')->nullable()->after('referred_by');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['last_purchase_date', 'last_purchase_amount', 'referred_by', 'notes']);
        });
    }
};
