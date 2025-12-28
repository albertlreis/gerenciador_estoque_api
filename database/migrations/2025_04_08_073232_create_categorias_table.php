<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome', 255);
            $table->text('descricao')->nullable();

            $table->unsignedInteger('categoria_pai_id')->nullable();

            $table->timestamps();

            $table->index('nome');
            $table->index('categoria_pai_id');

            $table->foreign('categoria_pai_id', 'categorias_pai_fk')
                ->references('id')->on('categorias')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
