<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro do cliente (10/11/2026): comorbidades (na pessoa ou na família), como conheceu a ótica e a idade informada
 * (quando não há data de nascimento; `age_recorded_on` deixa a idade crescer com o tempo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('comorbidities')->nullable()->after('address');
            $table->string('referral_source', 20)->nullable()->after('comorbidities');
            $table->unsignedTinyInteger('age')->nullable()->after('birth_date');
            $table->date('age_recorded_on')->nullable()->after('age');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['comorbidities', 'referral_source', 'age', 'age_recorded_on']);
        });
    }
};
