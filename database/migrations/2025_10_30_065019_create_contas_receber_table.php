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
    public function up(): void
    {
        Schema::create('contas_receber', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->onDelete('set null');
            $table->string('descricao')->nullable();
            $table->string('numero_documento')->nullable();
            $table->date('data_emissao')->nullable();
            $table->date('data_vencimento')->nullable();
            $table->decimal('valor_bruto', 15, 2)->default(0);
            $table->decimal('desconto', 15, 2)->default(0);
            $table->decimal('juros', 15, 2)->default(0);
            $table->decimal('multa', 15, 2)->default(0);
            $table->decimal('valor_liquido', 15, 2)->default(0);
            $table->decimal('valor_recebido', 15, 2)->default(0);
            $table->decimal('saldo_aberto', 15, 2)->default(0);
            $table->enum('status', ['ABERTO', 'PARCIAL', 'PAGO', 'CANCELADO'])->default('ABERTO');
            $table->string('forma_recebimento')->nullable();
            $table->string('centro_custo')->nullable();
            $table->string('categoria')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('contas_receber');
    }
};
