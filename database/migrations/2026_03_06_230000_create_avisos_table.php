<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->text('conteudo');
            $table->boolean('ativo')->default(true);
            $table->dateTime('data_inicio')->nullable();
            $table->dateTime('data_fim')->nullable();
            $table->unsignedInteger('criado_por')->nullable();
            $table->timestamps();

            $table->index(['ativo', 'data_inicio', 'data_fim'], 'idx_avisos_vigencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos');
    }
};
