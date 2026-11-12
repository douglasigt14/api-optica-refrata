<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM da Ótica, fase 2 (12/11/2026): o cliente em cada atendimento (`crm_entries`, a etapa do CRM) e o histórico
 * (`crm_activities`: contatos registrados, mudanças de etapa à mão e as automáticas). "A contatar" = cliente da cidade
 * ainda sem linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 20)->default('to_contact');
            $table->date('appointment_date')->nullable();
            $table->date('follow_up_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->string('last_result', 20)->nullable();
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamps();
            $table->unique(['city_visit_id', 'client_id']);
            $table->index(['city_visit_id', 'stage']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10);
            $table->string('channel', 20)->nullable();
            $table->string('result', 20)->nullable();
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20)->nullable();
            $table->text('notes')->nullable();
            $table->date('follow_up_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
            $table->index(['city_visit_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_entries');
    }
};
