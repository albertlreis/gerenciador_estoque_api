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
        Schema::create('clientes', function (Blueprint $table) {
            $table->increments('id');

            // Dados principais
            $table->string('nome', 255);
            $table->string('nome_fantasia')->nullable()->comment('Nome fantasia do cliente (apenas para pessoa jurídica)');
            $table->string('documento', 50)->nullable();
            $table->string('inscricao_estadual')->nullable()->comment('Inscrição estadual (apenas para pessoa jurídica)');
            $table->string('email', 100)->nullable();
            $table->string('telefone', 50)->nullable();
            $table->string('whatsapp', 20)->nullable();

            // Endereço
            $table->text('endereco')->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('complemento', 255)->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->string('estado', 2)->nullable();
            $table->string('cep', 20)->nullable();

            // Tipo de cliente
            $table->string('tipo', 10)->default('pf')->comment('Tipo de cliente: pf (Pessoa Física) ou pj (Pessoa Jurídica)');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('clientes');
    }
};
