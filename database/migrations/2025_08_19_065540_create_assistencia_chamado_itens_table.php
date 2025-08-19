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
    public function up()
    {
        Schema::create('assistencia_chamado_itens', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('chamado_id');

            $table->unsignedInteger('produto_id')->nullable();
            $table->unsignedInteger('variacao_id')->nullable();

            $table->string('numero_serie', 120)->nullable()->index();
            $table->string('lote', 120)->nullable();

            $table->unsignedInteger('defeito_id')->nullable();
            $table->string('descricao_defeito_livre', 255)->nullable();

            $table->string('status_item', 50)->index();

            // vínculos operacionais (ajuste nomes se necessário)
            $table->unsignedInteger('pedido_id')->nullable();
            $table->unsignedInteger('pedido_item_id')->nullable();
            $table->unsignedInteger('consignacao_id')->nullable();
            $table->unsignedInteger('consignacao_item_id')->nullable();

            // logística
            $table->unsignedInteger('deposito_origem_id')->nullable();
            $table->unsignedInteger('assistencia_id')->nullable(); // por item
            $table->unsignedInteger('deposito_assistencia_id')->nullable();
            $table->string('rastreio_envio', 150)->nullable();
            $table->string('rastreio_retorno', 150)->nullable();
            $table->date('data_envio')->nullable();
            $table->date('data_retorno')->nullable();

            // orçamento
            $table->decimal('valor_orcado', 12, 2)->nullable();
            $table->string('aprovacao', 20)->default('pendente'); // pendente|aprovado|reprovado
            $table->date('data_aprovacao')->nullable();

            $table->text('observacoes')->nullable();

            $table->timestamps();

            $table->foreign('chamado_id')->references('id')->on('assistencia_chamados')->onDelete('cascade');

            $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('set null');
            $table->foreign('variacao_id')->references('id')->on('produto_variacoes')->onDelete('set null');

            $table->foreign('defeito_id')->references('id')->on('assistencia_defeitos')->onDelete('set null');

            // ajuste nomes de tabelas abaixo conforme seu schema:
            $table->foreign('pedido_id')->references('id')->on('pedidos')->onDelete('set null');
            $table->foreign('pedido_item_id')->references('id')->on('pedido_itens')->onDelete('set null');

            $table->foreign('consignacao_id')->references('id')->on('consignacoes')->onDelete('set null');
            $table->foreign('consignacao_item_id')->references('id')->on('consignacao_itens')->onDelete('set null');

            $table->foreign('deposito_origem_id')->references('id')->on('depositos')->onDelete('set null');
            $table->foreign('deposito_assistencia_id')->references('id')->on('depositos')->onDelete('set null');

            $table->foreign('assistencia_id')->references('id')->on('assistencias')->onDelete('set null');

            $table->index(['aprovacao', 'data_aprovacao']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('assistencia_chamado_itens');
    }
};
