<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sigilo do telefone (12/11/2026): cada vez que alguém abre o WhatsApp de um cliente ou de uma indicação ("Ligar pelo
 * WhatsApp"), o número sai da API só naquela hora e fica registrado quem abriu, de quem e quando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('subject');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_reveals');
    }
};
