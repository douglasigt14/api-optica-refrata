<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Senhas da fila do atendimento (12/11/2026): normal (N001…) e prioridade (P001…), cada tipo contando do 1 em cada
 * atendimento. A senha é emitida (impressa na térmica) e depois chamada (`called_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_visit_id')->constrained()->cascadeOnDelete();
            // `normal` ou `priority`.
            $table->string('type', 10);
            $table->unsignedInteger('number');
            $table->timestamp('called_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['city_visit_id', 'type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');
    }
};
