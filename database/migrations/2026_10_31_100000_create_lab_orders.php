<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passo 3 do roteiro (ótica ↔ laboratório, admin-refrata/PLANO-PLATAFORMA.md): a ótica manda pedidos aos laboratórios
 * do grupo e eles viram OS lá. `lab_connections`: cada laboratório ligado pelo Console (por ID; endereço e chave para
 * chamar o laboratório, criptografada, e o hash da chave com que o laboratório avisa a situação). `lab_orders`: o pedido
 * como foi mandado, o cliente, o nº da OS e a situação que o laboratório avisou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->ulid('lab_console_id'); // a empresa laboratório no Console
            $table->string('name');
            $table->string('url')->nullable();
            $table->text('token')->nullable(); // criptografado
            $table->string('inbound_token_hash', 64)->nullable()->unique();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'lab_console_id']);
        });

        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('lab_connection_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload'); // o pedido como foi (ou será) mandado
            $table->string('prescription_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('send_error', 500)->nullable();
            $table->string('lab_number', 20)->nullable(); // nº da OS no laboratório
            $table->string('status', 30)->nullable()->index();
            $table->string('status_label', 60)->nullable();
            $table->boolean('awaits_frame')->default(true);
            $table->string('cancel_reason')->nullable();
            $table->timestamp('status_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('lab_connections');
    }
};
