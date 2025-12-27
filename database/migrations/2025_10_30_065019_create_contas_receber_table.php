<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_receber', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('pedido_id')->nullable();
            $table->foreign('pedido_id')->references('id')->on('pedidos')->nullOnDelete();

            $table->string('descricao', 180)->nullable();
            $table->string('numero_documento', 80)->nullable();

            $table->date('data_emissao')->nullable();
            $table->date('data_vencimento')->nullable()->index();

            $table->decimal('valor_bruto', 15, 2)->default(0);
            $table->decimal('desconto', 15, 2)->default(0);
            $table->decimal('juros', 15, 2)->default(0);
            $table->decimal('multa', 15, 2)->default(0);

            // seu seeder/service setam isso
            $table->decimal('valor_liquido', 15, 2)->default(0);

            $table->decimal('valor_recebido', 15, 2)->default(0);
            $table->decimal('saldo_aberto', 15, 2)->default(0);

            $table->string('status', 20)->default('ABERTA')->index();
            $table->string('forma_recebimento', 30)->nullable()->index();

            $table->foreignId('categoria_id')->nullable()
                ->constrained('categorias_financeiras')->nullOnDelete();

            $table->foreignId('centro_custo_id')->nullable()
                ->constrained('centros_custo')->nullOnDelete();

            $table->text('observacoes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'data_vencimento']);
            $table->index(['categoria_id', 'centro_custo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_receber');
    }
};
