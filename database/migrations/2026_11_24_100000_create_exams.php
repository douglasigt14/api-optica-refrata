<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exames (24/11/2026): o cadastro da ótica (nome e valor, ex.: Retinografia) e, na ficha, os exames em que o cliente tem
 * interesse — feitos na entrega. Na ficha fica uma cópia (id, nome e valor), para o cadastro mudar sem mexer nas fichas.
 * Não entra no total da ficha nem no Financeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->json('exams')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('exams');
        });
        Schema::dropIfExists('exams');
    }
};
