<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_variacao_imagens', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_variacao');
            $table->text('url');
            $table->timestamps();

            $table->unique('id_variacao', 'uq_pvi_variacao');

            $table->foreign('id_variacao', 'pvi_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->cascadeOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_variacao_imagens');
    }
};

