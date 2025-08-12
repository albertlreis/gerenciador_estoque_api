<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('feriados', function (Blueprint $table) {
            $table->id();
            $table->date('data');
            $table->string('nome');
            $table->enum('escopo', ['nacional','estadual']); // (municipal pode vir depois)
            $table->string('uf', 2)->nullable();             // ex.: 'PA' quando estadual
            $table->string('fonte')->nullable();             // brasilapi|nager|calendarific|manual
            $table->unsignedSmallInteger('ano');
            $table->timestamps();

            // Não permitir duplicado no mesmo dia para o mesmo escopo/UF
            $table->unique(['data','escopo','uf']);
            $table->index(['ano','uf','escopo']);
            $table->index('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }
};
