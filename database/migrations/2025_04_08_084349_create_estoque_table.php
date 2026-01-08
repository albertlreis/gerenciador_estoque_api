<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('id_deposito');

            $table->integer('quantidade')->default(0);
            $table->timestamps();

            $table->unique(['id_variacao', 'id_deposito'], 'uq_estoque');

            $table->foreign('id_variacao', 'estoque_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_deposito', 'estoque_deposito_fk')
                ->references('id')->on('depositos')
                ->cascadeOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque');
    }
};
