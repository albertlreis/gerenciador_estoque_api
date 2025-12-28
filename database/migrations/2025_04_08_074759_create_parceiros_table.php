<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiros', function (Blueprint $table) {
            $table->increments('id');

            $table->string('nome', 255);
            $table->string('tipo', 50);          // ex: arquiteto, designer, lojista...
            $table->string('documento', 50);     // CPF/CNPJ

            $table->string('email', 100)->nullable();
            $table->string('telefone', 50)->nullable();
            $table->text('endereco')->nullable();

            $table->tinyInteger('status')->default(1)->comment('1=ativo,0=inativo');
            $table->text('observacoes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'idx_parceiros_status');
            $table->index('nome', 'idx_parceiros_nome');
            $table->index('tipo', 'idx_parceiros_tipo');
            $table->unique('documento', 'uq_parceiros_documento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiros');
    }
};
