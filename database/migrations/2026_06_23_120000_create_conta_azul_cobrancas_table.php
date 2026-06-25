<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('conta_azul_cobrancas')) {
            return;
        }

        Schema::create('conta_azul_cobrancas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conta_receber_id')->unique();
            $table->unsignedBigInteger('loja_id')->nullable()->index();
            $table->string('tipo', 32)->default('BOLETO')->index();
            $table->string('status', 32)->default('pendente')->index();
            $table->string('id_externo', 64)->nullable()->index();
            $table->string('url', 1024)->nullable();
            $table->string('linha_digitavel', 255)->nullable();
            $table->string('codigo_barras', 255)->nullable();
            $table->json('payload_json')->nullable();
            $table->json('response_json')->nullable();
            $table->text('payload_resumo')->nullable();
            $table->text('resposta_resumo')->nullable();
            $table->string('erro_codigo', 64)->nullable();
            $table->text('erro_mensagem')->nullable();
            $table->dateTime('emitida_em')->nullable()->index();
            $table->dateTime('ultima_tentativa_em')->nullable()->index();
            $table->timestamps();

            $table->foreign('conta_receber_id')
                ->references('id')
                ->on('contas_receber')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conta_azul_cobrancas');
    }
};
