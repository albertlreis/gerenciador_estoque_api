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
        Schema::create('categorias_financeiras', function (Blueprint $table) {
            $table->id();

            $table->string('nome', 120);
            $table->string('slug', 140)->nullable()->unique();

            // null = serve para ambos; ou "receita" / "despesa"
            $table->string('tipo', 20)->nullable()->index();

            // hierarquia (categoria pai)
            $table->foreignId('categoria_pai_id')
                ->nullable()
                ->constrained('categorias_financeiras')
                ->nullOnDelete();

            // visual/ordenação
            $table->unsignedInteger('ordem')->default(0)->index();

            // flags
            $table->boolean('ativo')->default(true)->index();
            $table->boolean('padrao')->default(false)->index(); // ex.: "Outros"

            // metadados livres (ex.: cor, ícone, integrações)
            $table->json('meta_json')->nullable();

            $table->timestamps();

            // índices úteis
            $table->index(['tipo', 'ativo']);
            $table->index(['categoria_pai_id', 'ordem']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('categorias_financeiras');
    }
};
