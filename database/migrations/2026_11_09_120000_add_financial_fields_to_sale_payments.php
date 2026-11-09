<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baixa feita no Financeiro entra na ficha (09/11/2026). `from_financial`: o pagamento veio de lá; `financial_payment_id`:
 * a baixa de lá que ainda não voltou no estado da ficha (some quando volta); `financial_account_id`: a conta de lá onde
 * a baixa entrou (vale no lugar da conta da forma de pagamento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->boolean('from_financial')->default(false)->after('paid_on');
            $table->unsignedBigInteger('financial_payment_id')->nullable()->after('from_financial');
            $table->unsignedBigInteger('financial_account_id')->nullable()->after('financial_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn(['from_financial', 'financial_payment_id', 'financial_account_id']);
        });
    }
};
