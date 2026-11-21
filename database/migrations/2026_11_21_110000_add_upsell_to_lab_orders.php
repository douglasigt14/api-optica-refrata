<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upsell do óculos (21/11/2026): o que foi vendido a mais (tratamentos do catálogo do laboratório e limpa lentes, com a
 * quantidade) e quem vendeu (nome digitado). Sem valor: não mexe no total da ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->json('upsell')->nullable()->after('price');
            $table->timestamp('upsell_at')->nullable()->after('upsell');
            $table->foreignId('upsell_by')->nullable()->after('upsell_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('upsell_by');
            $table->dropColumn(['upsell', 'upsell_at']);
        });
    }
};
