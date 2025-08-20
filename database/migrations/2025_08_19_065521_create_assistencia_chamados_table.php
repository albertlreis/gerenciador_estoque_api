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
        Schema::create('assistencia_chamados', function (Blueprint $table) {
            $table->increments('id');
            $table->string('numero', 50)->unique(); // gerar no service
            $table->enum('origem_tipo', ['pedido','consignacao','estoque']);
            $table->unsignedInteger('origem_id')->nullable();

            $table->unsignedInteger('cliente_id')->nullable();
            $table->unsignedInteger('fornecedor_id')->nullable();

            $table->unsignedInteger('assistencia_id')->nullable(); // pode ser definido depois

            $table->string('status', 50)->index();   // enum lógico (string)
            $table->string('prioridade', 20)->default('media')->index(); // enum lógico (string)
            $table->date('sla_data_limite')->nullable();

            $table->string('canal_abertura', 30)->nullable();
            $table->text('observacoes')->nullable();

            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();

            $table->timestamps();

            // Ajuste nomes das tabelas-alvo se necessário no seu projeto:
            $table->foreign('cliente_id')->references('id')->on('clientes')->onDelete('set null');
            $table->foreign('fornecedor_id')->references('id')->on('fornecedores')->onDelete('set null');
            $table->foreign('assistencia_id')->references('id')->on('assistencias')->onDelete('set null');

            $table->index(['status', 'assistencia_id']);
            $table->index(['cliente_id', 'fornecedor_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('assistencia_chamados');
    }
};
