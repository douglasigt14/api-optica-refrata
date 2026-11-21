<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notificação de entregas (21/11/2026): por ficha, se o cliente já foi avisado de que os óculos chegaram, quem avisou e
 * quando, e o parecer (o que a pessoa disse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->timestamp('delivery_notified_at')->nullable()->after('delivered_at');
            $table->foreignId('delivery_notified_by')->nullable()->after('delivery_notified_at')->constrained('users')->nullOnDelete();
            $table->text('delivery_notice')->nullable()->after('delivery_notified_by');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_notified_by');
            $table->dropColumn(['delivery_notified_at', 'delivery_notice']);
        });
    }
};
