<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_preferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')
                ->constrained('acesso_usuarios')
                ->cascadeOnDelete();
            $table->string('chave', 120);
            $table->json('valor')->nullable();
            $table->timestamps();

            $table->unique(['usuario_id', 'chave'], 'usuario_preferencias_usuario_chave_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_preferencias');
    }
};
