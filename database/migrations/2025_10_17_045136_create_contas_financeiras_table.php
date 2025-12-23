<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('contas_financeiras', function (Blueprint $table) {
            $table->id();

            $table->string('nome', 120);
            $table->string('slug', 140)->nullable()->unique();

            // Ex.: banco, caixa, carteira, pix, investimento...
            $table->string('tipo', 30)->default('banco')->index();

            // Instituição / banco
            $table->string('banco_nome', 120)->nullable();
            $table->string('banco_codigo', 10)->nullable(); // ex: 001
            $table->string('agencia', 20)->nullable();
            $table->string('agencia_dv', 5)->nullable();
            $table->string('conta', 30)->nullable();
            $table->string('conta_dv', 5)->nullable();

            // Identificação do titular (opcional)
            $table->string('titular_nome', 140)->nullable();
            $table->string('titular_documento', 30)->nullable(); // CPF/CNPJ sem máscara ou com, você decide
            $table->string('chave_pix', 140)->nullable();

            // Controle operacional
            $table->char('moeda', 3)->default('BRL')->index();
            $table->boolean('ativo')->default(true)->index();
            $table->boolean('padrao')->default(false)->index();

            // Opcional: controle do saldo inicial (saldo atual normalmente vem de lançamentos/conciliador)
            $table->decimal('saldo_inicial', 14, 2)->default(0);

            // Observações e metadados
            $table->text('observacoes')->nullable();
            $table->json('meta_json')->nullable();

            $table->timestamps();

            // índices úteis
            $table->index(['tipo', 'ativo']);
            $table->index(['banco_codigo', 'agencia', 'conta']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('contas_financeiras');
    }
};
