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
        Schema::create('produto_variacoes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_produto');
            $table->string('sku', 100)->unique();
            $table->string('nome', 255);
            $table->decimal('preco', 10, 2);
            $table->decimal('custo', 10, 2);
            $table->decimal('peso', 10, 2);
            $table->decimal('altura', 10, 2);
            $table->decimal('largura', 10, 2);
            $table->decimal('profundidade', 10, 2);
            $table->string('codigo_barras', 100)->nullable();
            $table->timestamps();

            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('produto_variacoes');
    }
};
