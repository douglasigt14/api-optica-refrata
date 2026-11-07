<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendas na Ótica — a promissória (07/11/2026). A venda junta o cliente que paga e um ou mais óculos (pedidos ao
 * laboratório, cada um com o paciente e o valor), com a entrada e o restante na entrega. "Lançar venda" gera o título
 * a receber no Financeiro (ligação feita pelo Console: endereço e chave aqui na empresa, nunca em `.env`); o admin
 * escolhe a conta do Financeiro de cada forma de pagamento (`sale_accounts`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('financial_api_url')->nullable()->after('financial_url');
            $table->text('financial_token')->nullable()->after('financial_api_url');
            $table->json('sale_accounts')->nullable()->after('financial_token');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained();
            $table->date('expected_delivery_date');
            $table->text('notes')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->timestamp('financial_synced_at')->nullable();
            $table->string('financial_error', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'city_visit_id']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            // `down` = entrada (até entregar), `delivery` = recebido na entrega.
            $table->string('stage', 10);
            // `cash` (espécie), `pix`, `card` (com `installments`).
            $table->string('method', 10);
            $table->decimal('amount', 10, 2);
            $table->unsignedTinyInteger('installments')->nullable();
            $table->date('paid_on');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('lab_orders', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('city_visit_id')->constrained()->nullOnDelete();
            // Valor do óculos na venda.
            $table->decimal('price', 10, 2)->nullable()->after('sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
            $table->dropColumn('price');
        });
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sales');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['financial_api_url', 'financial_token', 'sale_accounts']);
        });
    }
};
