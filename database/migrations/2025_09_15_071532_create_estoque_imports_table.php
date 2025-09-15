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
        Schema::create('estoque_imports', function (Blueprint $table) {
            $table->id();
            $table->string('arquivo_nome');
            $table->string('arquivo_hash')->unique();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->enum('status', ['pendente','processando','concluido','com_erro','cancelado'])->default('pendente');
            $table->unsignedInteger('linhas_total')->default(0);
            $table->unsignedInteger('linhas_processadas')->default(0);
            $table->unsignedInteger('linhas_validas')->default(0);
            $table->unsignedInteger('linhas_invalidas')->default(0);
            $table->json('metricas')->nullable(); // counts e agregados
            $table->text('mensagem')->nullable();
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
        Schema::dropIfExists('estoque_imports');
    }
};
