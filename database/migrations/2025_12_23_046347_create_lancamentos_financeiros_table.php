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
        Schema::create('lancamentos_financeiros', function (Blueprint $table) {
            $table->id();

            $table->string('descricao', 255);
            // receita | despesa
            $table->string('tipo', 20)->index();

            // pendente | pago | cancelado (atrasado é derivado)
            $table->string('status', 20)->default('pendente')->index();

            $table->unsignedBigInteger('categoria_id')->nullable()->index();
            $table->unsignedBigInteger('conta_id')->nullable()->index();

            $table->decimal('valor', 12, 2);

            $table->dateTime('data_vencimento')->index();
            $table->dateTime('data_pagamento')->nullable()->index();

            // Opcional (dashboard por competência depois). Pode ficar nulo por enquanto.
            $table->date('competencia')->nullable()->index();

            $table->text('observacoes')->nullable();

            // Opcional: vínculo com outras entidades (pedido, boleto etc.)
            $table->string('referencia_type', 120)->nullable()->index();
            $table->unsignedBigInteger('referencia_id')->nullable()->index();

            // Quem criou
            $table->unsignedInteger('created_by')->nullable()->index();

            $table->softDeletes();
            $table->timestamps();

            // FKs (ajuste os nomes das tabelas se no seu projeto forem outros)
            $table->foreign('categoria_id')
                ->references('id')->on('categorias_financeiras')
                ->nullOnDelete();

            $table->foreign('conta_id')
                ->references('id')->on('contas_financeiras')
                ->nullOnDelete();

            $table->foreign('created_by')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('lancamentos_financeiros');
    }
};
