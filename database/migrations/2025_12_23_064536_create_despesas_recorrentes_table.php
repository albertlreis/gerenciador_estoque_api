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
            $table->foreign('fornecedor_id')->references('id')->on('fornecedores')->nullOnDelete()->onUpdate('restrict');

            $table->string('descricao', 180);
            $table->string('numero_documento', 80)->nullable();

            $table->foreignId('categoria_id')->nullable()
                ->constrained('categorias_financeiras')->nullOnDelete();

            $table->foreignId('centro_custo_id')->nullable()
                ->constrained('centros_custo')->nullOnDelete();

            $table->decimal('valor_bruto', 15, 2)->nullable();
            $table->decimal('desconto', 15, 2)->default(0);
            $table->decimal('juros', 15, 2)->default(0);
            $table->decimal('multa', 15, 2)->default(0);

            $table->string('tipo', 20)->default('FIXA')->index();
            $table->string('frequencia', 20)->default('MENSAL')->index();
            $table->unsignedSmallInteger('intervalo')->default(1);

            $table->unsignedTinyInteger('dia_vencimento')->nullable();
            $table->unsignedTinyInteger('mes_vencimento')->nullable();

            $table->date('data_inicio');
            $table->date('data_fim')->nullable();

            $table->boolean('criar_conta_pagar_auto')->default(true);
            $table->unsignedSmallInteger('dias_antecedencia')->default(0);
            $table->string('status', 20)->default('ATIVA')->index();

            $table->text('observacoes')->nullable();

            $table->foreignId('usuario_id')->nullable()->index();
            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->nullOnDelete()->onUpdate('restrict');

            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'data_inicio', 'data_fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despesas_recorrentes');
    }
};
