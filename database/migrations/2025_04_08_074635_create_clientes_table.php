<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->increments('id');

            // Dados principais
            $table->string('nome', 255);
            $table->string('nome_fantasia')->nullable()->comment('Nome fantasia (PJ)');
            $table->string('documento', 50)->nullable(); // CPF/CNPJ (nullable permite cadastros sem doc)
            $table->string('inscricao_estadual')->nullable()->comment('IE (PJ)');
            $table->string('email', 100)->nullable();
            $table->string('telefone', 50)->nullable();
            $table->string('whatsapp', 20)->nullable();

            // Tipo
            $table->enum('tipo', ['pf', 'pj'])->default('pf')
                ->comment('pf (Pessoa Física) ou pj (Pessoa Jurídica)');

            $table->timestamps();

            // Índices
            $table->index('nome', 'idx_clientes_nome');
            $table->index('tipo', 'idx_clientes_tipo');
            $table->unique('documento', 'uq_clientes_documento'); // MySQL permite múltiplos NULLs
            $table->index('email', 'idx_clientes_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
