<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 100)->unique();
            $table->string('label', 150)->nullable();
            $table->string('tipo', 20)->default('string'); // ex: string, integer, boolean
            $table->text('valor');
            $table->text('descricao')->nullable(); // nova coluna para exibir help text no front
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
    }
};
