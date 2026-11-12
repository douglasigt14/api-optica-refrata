<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM da Ótica, fase 4 (12/11/2026): WhatsApp pela API não oficial (Evolution), com a oficial (Meta) depois atrás da
 * mesma interface. Números da ótica (endereço e chave do servidor no banco, criptografados; nunca em `.env`), conversas
 * (o número da pessoa fica criptografado e só o servidor usa), mensagens (texto e áudio), modelos de mensagem e o disparo
 * em massa (fase 5, pré-pronto: só com número da API oficial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('is_default')->default(false);
            $table->string('provider', 20)->default('evolution');
            $table->string('instance', 80)->unique();
            $table->string('base_url');
            $table->text('api_key');
            $table->string('webhook_secret', 64);
            $table->string('status', 20)->default('disconnected');
            $table->string('own_phone', 20)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->text('remote_jid');
            $table->char('remote_hash', 64);
            $table->string('push_name', 120)->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 160)->nullable();
            $table->timestamps();
            $table->unique(['whatsapp_number_id', 'remote_hash']);
            $table->index(['company_id', 'last_message_at']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 12)->default('text');
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime', 80)->nullable();
            $table->string('external_id', 120)->nullable()->index();
            $table->string('status', 12)->default('sent');
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('whatsapp_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('filters')->nullable();
            $table->unsignedSmallInteger('batch_size')->default(50);
            $table->unsignedSmallInteger('interval_seconds')->default(30);
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_broadcasts');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_numbers');
    }
};
