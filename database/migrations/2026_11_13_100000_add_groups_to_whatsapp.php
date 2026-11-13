<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos e parceiros nas Conversas (13/11/2026): a conversa marca se é grupo (o nome do grupo fica em `push_name`) e pode
 * ser de um parceiro; a mensagem do grupo guarda o nome do WhatsApp de quem escreveu e o identificador dele criptografado
 * (para cadastrar a pessoa como cliente ou parceiro sem o número aparecer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->boolean('is_group')->default(false)->after('push_name');
            $table->foreignId('prospector_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
        });
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('sender_name', 120)->nullable()->after('user_id');
            $table->text('sender_jid')->nullable()->after('sender_name');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prospector_id');
            $table->dropColumn('is_group');
        });
        Schema::table('whatsapp_messages', fn (Blueprint $table) => $table->dropColumn(['sender_name', 'sender_jid']));
    }
};
