<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProdutoVariacaoOutletsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('produto_variacao_outlets', function (Blueprint $table) {
            $table->id();

            // Variação vinculada
            $table->unsignedInteger('produto_variacao_id');
            $table->foreign('produto_variacao_id')
                ->references('id')
                ->on('produto_variacoes')
                ->onDelete('cascade');

            // Motivo do outlet (string, validado na aplicação)
            $table->string('motivo', 50);

            // Quantidade original e restante
            $table->unsignedInteger('quantidade');
            $table->unsignedInteger('quantidade_restante');

            // Usuário responsável
            $table->unsignedInteger('usuario_id');
            $table->foreign('usuario_id')
                ->references('id')
                ->on('acesso_usuarios')
                ->onDelete('restrict');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produto_variacao_outlets');
    }
}
