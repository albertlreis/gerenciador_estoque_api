<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despesas_recorrentes', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('fornecedor_id')->nullable();
            $table->string('descricao', 180);
            $table->string('numero_documento', 80)->nullable();

            $table->string('centro_custo', 60)->nullable();
            $table->string('categoria', 60)->nullable();

            $table->decimal('valor_bruto', 15, 2)->nullable(); // pode ser null se "variável"
            $table->decimal('desconto', 15, 2)->default(0);
            $table->decimal('juros', 15, 2)->default(0);
            $table->decimal('multa', 15, 2)->default(0);

            $table->string('tipo', 20)->default('FIXA')->index(); // FIXA | VARIAVEL
            $table->string('frequencia', 20)->default('MENSAL')->index(); // DIARIA | SEMANAL | MENSAL | ANUAL | PERSONALIZADA
            $table->unsignedSmallInteger('intervalo')->default(1); // a cada X frequências
            $table->unsignedTinyInteger('dia_vencimento')->nullable(); // 1..31 (para mensal/anual)
            $table->unsignedTinyInteger('mes_vencimento')->nullable(); // 1..12 (para anual)
            $table->date('data_inicio');
            $table->date('data_fim')->nullable();

            $table->boolean('criar_conta_pagar_auto')->default(true);
            $table->unsignedSmallInteger('dias_antecedencia')->default(0); // gerar antes do vencimento
            $table->string('status', 20)->default('ATIVA')->index(); // ATIVA | PAUSADA | CANCELADA

            $table->text('observacoes')->nullable();

            $table->unsignedInteger('usuario_id')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('fornecedor_id')->references('id')->on('fornecedores')->onDelete('set null');
            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->onDelete('set null');

            $table->index(['status', 'data_inicio', 'data_fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despesas_recorrentes');
    }
};
