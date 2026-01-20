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
        Schema::create('estoque_transferencias', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->unsignedInteger('deposito_origem_id');
            $table->unsignedInteger('deposito_destino_id');

            // Ajuste o nome da tabela caso seja "users" em vez de "usuarios"
            $table->unsignedBigInteger('id_usuario')->nullable();

            $table->text('observacao')->nullable();

            $table->string('status', 30)->default('concluida'); // ou 'aberta' se quiser 2 etapas
            $table->unsignedInteger('total_itens')->default(0);
            $table->unsignedInteger('total_pecas')->default(0);

            $table->timestamp('concluida_em')->nullable();

            $table->timestamps();

            $table->foreign('deposito_origem_id')->references('id')->on('depositos');
            $table->foreign('deposito_destino_id')->references('id')->on('depositos');
            $table->foreign('id_usuario')->references('id')->on('acesso_usuarios'); // ajuste se necessário

            $table->index(['deposito_origem_id', 'deposito_destino_id'], 'idx_est_transf_dep_origem_destino');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('estoque_transferencias');
    }
};
