<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega prevista do atendimento (09/11/2026): é do atendimento como um todo, não de cada ficha. Vazia = o último dia do
 * atendimento + 5 dias (`CityVisit::deliveryDate()`). As fichas do atendimento nascem com ela e acompanham quando muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('city_visits', function (Blueprint $table) {
            $table->date('expected_delivery_date')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('city_visits', function (Blueprint $table) {
            $table->dropColumn('expected_delivery_date');
        });
    }
};
