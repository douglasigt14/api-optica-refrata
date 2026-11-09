<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OS de Ótica por empresa (09/11/2026): cada empresa tem a sua sequência (`number`), como no laboratório. Os pedidos que
 * já existem são renumerados 1, 2, 3… em cada empresa, na ordem em que foram criados; a sequência segue do maior dela. O `id`
 * continua sendo o `external_id` mandado ao laboratório (é único na instalação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('company_id');
        });

        $next = [];
        foreach (DB::table('lab_orders')->orderBy('id')->get(['id', 'company_id']) as $order) {
            $next[$order->company_id] = ($next[$order->company_id] ?? 0) + 1;
            DB::table('lab_orders')->where('id', $order->id)->update(['number' => $next[$order->company_id]]);
        }

        Schema::table('lab_orders', function (Blueprint $table) {
            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'number']);
            $table->dropColumn('number');
        });
    }
};
