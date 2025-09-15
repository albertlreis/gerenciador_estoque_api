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
        Schema::create('pedido_fabrica_status_historicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_fabrica_id')->constrained('pedidos_fabrica')->cascadeOnDelete();
            $table->enum('status', ['pendente', 'enviado', 'parcial', 'entregue', 'cancelado']);
            $table->unsignedBigInteger('usuario_id')->nullable(); // opcional, se não usar auth padrão adapte
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['pedido_fabrica_id', 'created_at'], 'pfsh_pf_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pedido_fabrica_status_historicos');
    }
};
