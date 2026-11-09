<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OS de Ótica por empresa (09/11/2026): cada empresa tem a sua sequência (`number`), como no laboratório. Os pedidos que
 * já existem ficam com o número de hoje (`number` = `id`); a sequência de cada empresa segue do maior dela. O `id`
 * continua sendo o `external_id` mandado ao laboratório (é único na instalação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('company_id');
        });

        DB::table('lab_orders')->whereNull('number')->update(['number' => DB::raw('id')]);

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
