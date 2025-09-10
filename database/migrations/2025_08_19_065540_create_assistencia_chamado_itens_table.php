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
        Schema::create('assistencia_chamado_itens', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('chamado_id');

            // Vínculos de produto
            $table->unsignedInteger('variacao_id')->nullable();     // sempre que possível
            $table->unsignedInteger('pedido_item_id')->nullable();  // quando origem = pedido

            // Catálogo de defeitos
            $table->unsignedInteger('defeito_id')->nullable();

            // Status do item
            $table->string('status_item', 50)->index();

            // Prazos/nota
            $table->date('prazo_finalizacao')->nullable();
            $table->string('nota_numero', 60)->nullable();

            // Operacional/logística
            $table->unsignedBigInteger('consignacao_id')->nullable();
            $table->unsignedInteger('deposito_origem_id')->nullable();
            $table->unsignedInteger('deposito_assistencia_id')->nullable();
            $table->string('rastreio_envio', 150)->nullable();
            $table->string('rastreio_retorno', 150)->nullable();
            $table->date('data_envio')->nullable();
            $table->date('data_retorno')->nullable();

            // Orçamento
            $table->decimal('valor_orcado', 12)->nullable();
            $table->string('aprovacao', 20)->default('pendente');
            $table->date('data_aprovacao')->nullable();

            $table->text('observacoes')->nullable();

            $table->timestamps();

            // FKs
            $table->foreign('chamado_id')->references('id')->on('assistencia_chamados')->onDelete('cascade');
            $table->foreign('variacao_id')->references('id')->on('produto_variacoes')->onDelete('set null');
            $table->foreign('defeito_id')->references('id')->on('assistencia_defeitos')->onDelete('set null');

            $table->foreign('pedido_item_id')->references('id')->on('pedido_itens')->onDelete('set null');
            $table->foreign('consignacao_id')->references('id')->on('consignacoes')->onDelete('set null');

            $table->foreign('deposito_origem_id')->references('id')->on('depositos')->onDelete('set null');
            $table->foreign('deposito_assistencia_id')->references('id')->on('depositos')->onDelete('set null');

            $table->index(['aprovacao', 'data_aprovacao']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('assistencia_chamado_itens');
    }
};
