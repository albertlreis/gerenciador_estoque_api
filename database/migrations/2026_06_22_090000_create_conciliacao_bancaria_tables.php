<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conciliacao_bancaria_importacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_financeira_id')
                ->constrained('contas_financeiras')
                ->restrictOnDelete();
            $table->string('banco_codigo', 20)->nullable()->index();
            $table->string('banco_nome', 160)->nullable();
            $table->string('agencia', 30)->nullable();
            $table->string('conta', 60)->nullable();
            $table->string('conta_dv', 10)->nullable();
            $table->char('moeda', 3)->default('BRL')->index();
            $table->date('data_inicio')->nullable()->index();
            $table->date('data_fim')->nullable()->index();
            $table->decimal('saldo_final', 15, 2)->nullable();
            $table->dateTime('saldo_final_em')->nullable()->index();
            $table->string('arquivo_hash', 64)->index();
            $table->string('status', 24)->default('processada')->index();
            $table->json('resumo_json')->nullable();
            $table->foreignId('created_by')->nullable()->index()
                ->constrained('acesso_usuarios')
                ->nullOnDelete()
                ->restrictOnUpdate();
            $table->timestamps();

            $table->index(['conta_financeira_id', 'data_inicio', 'data_fim'], 'ix_cbi_conta_periodo');
        });

        Schema::create('conciliacao_bancaria_transacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacao_id')
                ->constrained('conciliacao_bancaria_importacoes')
                ->cascadeOnDelete();
            $table->foreignId('conta_financeira_id')
                ->constrained('contas_financeiras')
                ->restrictOnDelete();
            $table->string('fit_id', 120)->nullable()->index();
            $table->string('identificador', 128);
            $table->string('hash_unico', 64)->index();
            $table->date('data_movimento')->index();
            $table->decimal('valor', 15, 2);
            $table->string('tipo_ofx', 40)->nullable()->index();
            $table->string('checknum', 120)->nullable();
            $table->text('memo')->nullable();
            $table->string('status', 24)->default('pendente')->index();
            $table->string('candidato_tipo', 40)->nullable()->index();
            $table->unsignedBigInteger('candidato_id')->nullable()->index();
            $table->unsignedTinyInteger('candidato_score')->nullable()->index();
            $table->string('candidato_motivo', 255)->nullable();
            $table->json('candidato_json')->nullable();
            $table->string('forma_pagamento', 50)->nullable();
            $table->string('pagamento_type', 190)->nullable()->index();
            $table->unsignedBigInteger('pagamento_id')->nullable()->index();
            $table->foreignId('lancamento_financeiro_id')->nullable()
                ->constrained('lancamentos_financeiros')
                ->nullOnDelete();
            $table->dateTime('conciliado_em')->nullable()->index();
            $table->foreignId('conciliado_por')->nullable()->index()
                ->constrained('acesso_usuarios')
                ->nullOnDelete()
                ->restrictOnUpdate();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->unique(['conta_financeira_id', 'identificador'], 'ux_cbt_conta_identificador');
            $table->index(['importacao_id', 'status'], 'ix_cbt_importacao_status');
            $table->index(['data_movimento', 'valor'], 'ix_cbt_data_valor');
            $table->index(['pagamento_type', 'pagamento_id'], 'ix_cbt_pagamento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conciliacao_bancaria_transacoes');
        Schema::dropIfExists('conciliacao_bancaria_importacoes');
    }
};
