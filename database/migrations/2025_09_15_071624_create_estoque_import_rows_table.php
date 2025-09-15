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
        Schema::create('estoque_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_id');
            $table->unsignedInteger('linha_planilha');
            $table->string('hash_linha', 64)->index(); // idempotência (arquivo+linha)
            // Colunas brutas
            $table->string('cod')->nullable();
            $table->string('nome')->nullable();
            $table->string('categoria')->nullable();
            $table->string('madeira')->nullable();
            $table->string('tecido_1')->nullable();
            $table->string('tecido_2')->nullable();
            $table->string('metal_vidro')->nullable();
            $table->string('localizacao')->nullable(); // ex: 6-F1 ou Área
            $table->string('deposito')->nullable();    // "Depósito" ou "Consignação" (será ignorado)
            $table->string('cliente')->nullable();
            $table->date('data_nf')->nullable();
            $table->date('data')->nullable();
            $table->decimal('valor', 12, 2)->nullable();
            $table->integer('qtd')->nullable();

            // Normalização / flags
            $table->json('parsed_dimensoes')->nullable(); // w,p,a,diam,clean
            $table->json('parsed_localizacao')->nullable(); // setor,coluna,nivel,area,codigo
            $table->boolean('valido')->default(false);
            $table->json('erros')->nullable();
            $table->json('warnings')->nullable();

            $table->timestamps();

            $table->foreign('import_id')->references('id')->on('estoque_imports')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('estoque_import_rows');
    }
};
