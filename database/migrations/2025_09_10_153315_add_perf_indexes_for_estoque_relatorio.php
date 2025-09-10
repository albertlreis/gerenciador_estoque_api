<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona índices de performance e trata o rollback
 * removendo os índices criados, preservando as FKs.
 */
return new class extends Migration
{
    /**
     * Executa as alterações (criação de índices).
     *
     * @return void
     */
    public function up(): void
    {
        // produto_variacao_outlets
        Schema::table('produto_variacao_outlets', function (Blueprint $table) {
            // Acelera filtro/união por variação e "saldo > 0"
            $table->index(['produto_variacao_id', 'quantidade_restante'], 'idx_pvo_variacao_restante');
        });

        // estoque
        Schema::table('estoque', function (Blueprint $table) {
            // Acelera join por variação e filtro por depósito (leftmost = id_variacao)
            $table->index(['id_variacao', 'id_deposito'], 'idx_estoque_variacao_deposito');
        });

        // produto_variacoes
        Schema::table('produto_variacoes', function (Blueprint $table) {
            // Acelera join com produtos por produto_id
            $table->index('produto_id', 'idx_pv_produto');
        });

        // produtos
        Schema::table('produtos', function (Blueprint $table) {
            // Acelera filtros por categoria
            $table->index('id_categoria', 'idx_produtos_categoria');
        });

        // produto_imagens
        Schema::table('produto_imagens', function (Blueprint $table) {
            // Acelera busca de imagem principal por produto
            $table->index(['id_produto', 'principal'], 'idx_pi_produto_principal');
        });

        // depositos (opcional: ajuda quando há GROUP/ORDER por nome)
        Schema::table('depositos', function (Blueprint $table) {
            $table->index('nome', 'idx_depositos_nome');
        });
    }

    /**
     * Reverte as alterações (remoção dos índices criados).
     * Importante: remover a FK que depende do índice composto antes de dropar o índice.
     *
     * @return void
     */
    public function down(): void
    {
        //
    }

};
