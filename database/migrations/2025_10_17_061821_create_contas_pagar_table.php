<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_pagar', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('fornecedor_id')->nullable();
            $table->foreign('fornecedor_id')->references('id')->on('fornecedores')->nullOnDelete();

            $table->string('descricao', 180);
            $table->string('numero_documento', 80)->nullable();

            $table->date('data_emissao')->nullable();
            $table->date('data_vencimento')->index();

            $table->decimal('valor_bruto', 15, 2);
            $table->decimal('desconto', 15, 2)->default(0);
            $table->decimal('juros', 15, 2)->default(0);
            $table->decimal('multa', 15, 2)->default(0);

            $table->decimal('valor_liquido', 15, 2)
                ->virtualAs('(valor_bruto - desconto + juros + multa)');

            $table->string('status', 20)->default('ABERTA')->index();

            $table->foreignId('categoria_id')->nullable()
                ->constrained('categorias_financeiras')->nullOnDelete();

            $table->foreignId('centro_custo_id')->nullable()
                ->constrained('centros_custo')->nullOnDelete();

            $table->text('observacoes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'data_vencimento']);
            $table->index(['categoria_id', 'centro_custo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_pagar');
    }
};
