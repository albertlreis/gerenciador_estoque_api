<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fornecedores', function (Blueprint $table) {
            $table->increments('id');

            $table->string('nome', 255);
            $table->string('cnpj', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefone', 30)->nullable();

            // endereço costuma estourar 255 com facilidade
            $table->text('endereco')->nullable();

            $table->tinyInteger('status')->default(1)->comment('1=ativo,0=inativo');
            $table->text('observacoes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('nome', 'idx_fornecedores_nome');
            $table->index('status', 'idx_fornecedores_status');
            $table->unique('cnpj', 'uq_fornecedores_cnpj'); // múltiplos NULLs permitidos
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornecedores');
    }
};
