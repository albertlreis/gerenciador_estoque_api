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
        Schema::create('contas_pagar', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('fornecedor_id')->nullable();
            $table->string('descricao', 180);
            $table->string('numero_documento', 80)->nullable();
            $table->date('data_emissao')->nullable();
            $table->date('data_vencimento');
            $table->decimal('valor_bruto', 15, 2);
            $table->decimal('desconto', 15, 2)->default(0);
            $table->decimal('juros', 15, 2)->default(0);
            $table->decimal('multa', 15, 2)->default(0);
            $table->decimal('valor_liquido', 15, 2)->virtualAs('(valor_bruto - desconto + juros + multa)');
            $table->string('status', 20)->index(); // ABERTA, PARCIAL, PAGA, CANCELADA
            $table->string('forma_pagamento', 30)->nullable(); // PIX, BOLETO, TED, DINHEIRO, CARTAO
            $table->string('centro_custo', 60)->nullable();
            $table->string('categoria', 60)->nullable();
            $table->text('observacoes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('fornecedor_id')->references('id')->on('fornecedores')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('contas_pagar');
    }
};
