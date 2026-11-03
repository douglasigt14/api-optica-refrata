<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido ao laboratório com o atendimento (03/11/2026): a cidade do cliente e o atendimento escolhido vão junto; com um
 * lote aberto desse atendimento lá, a OS entra no lote. `lab_batch` = o lote em que a OS está ("LT000045").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->foreignId('city_visit_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->string('lab_batch', 20)->nullable()->after('lab_number');
        });
    }

    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_visit_id');
            $table->dropColumn('lab_batch');
        });
    }
};
