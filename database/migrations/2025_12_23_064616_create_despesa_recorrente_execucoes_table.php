<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despesa_recorrente_execucoes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('despesa_recorrente_id');

            // Competência (use o 1º dia do mês como padrão para mensal)
            $table->date('competencia')->index();

            // Datas de controle
            $table->date('data_prevista')->index();
            $table->timestamp('data_geracao')->nullable();

            // Ligação com o que foi gerado
            $table->unsignedBigInteger('conta_pagar_id')->nullable();

            // Status / erro
            $table->string('status', 20)->default('PENDENTE')->index(); // PENDENTE | GERADA | IGNORADA | ERRO
            $table->text('erro_msg')->nullable();

            // Metadados (opcional mas muito útil)
            $table->json('meta_json')->nullable();

            $table->timestamps();

            $table->foreign('despesa_recorrente_id')
                ->references('id')
                ->on('despesas_recorrentes')
                ->onDelete('cascade');

            $table->foreign('conta_pagar_id')
                ->references('id')
                ->on('contas_pagar')
                ->onDelete('set null');

            // Evita duplicar execução da mesma competência
            $table->unique(['despesa_recorrente_id', 'competencia'], 'uniq_desp_recorrente_competencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despesa_recorrente_execucoes');
    }
};
