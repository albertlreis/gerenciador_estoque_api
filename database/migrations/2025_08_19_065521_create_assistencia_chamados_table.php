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
            $table->string('numero', 50)->unique();

            // origem: pedido|consignacao|estoque
            $table->enum('origem_tipo', ['pedido','consignacao','estoque']);
            $table->unsignedInteger('origem_id')->nullable();

            // vínculo oficial com pedido e autorizada
            $table->unsignedInteger('pedido_id')->nullable();
            $table->unsignedInteger('assistencia_id')->nullable();

            // campos de negócio
            $table->string('status', 50)->index();
            $table->string('prioridade', 20)->default('media')->index();
            $table->date('sla_data_limite')->nullable();

            $table->string('local_reparo', 20)->nullable();
            $table->string('custo_responsavel', 20)->nullable();

            $table->text('observacoes')->nullable();

            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            // FKs (ajuste nomes se necessário)
            $table->foreign('pedido_id')->references('id')->on('pedidos')->onDelete('set null');
            $table->foreign('assistencia_id')->references('id')->on('assistencias')->onDelete('set null');

            $table->index(['status', 'assistencia_id']);
            $table->index(['pedido_id']);
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
