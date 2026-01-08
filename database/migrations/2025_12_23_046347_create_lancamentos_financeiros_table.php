<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lancamentos_financeiros', function (Blueprint $table) {
            $table->id();

            $table->string('descricao', 255);
            $table->string('tipo', 20)->index();
            $table->string('status', 20)->default('confirmado')->index();

            $table->foreignId('categoria_id')->nullable()
                ->constrained('categorias_financeiras')
                ->nullOnDelete()
                ->restrictOnUpdate();

            $table->foreignId('centro_custo_id')->nullable()
                ->constrained('centros_custo')
                ->nullOnDelete()
                ->restrictOnUpdate();

            $table->foreignId('conta_id')->nullable()
                ->constrained('contas_financeiras')
                ->nullOnDelete()
                ->restrictOnUpdate();

            $table->decimal('valor', 15, 2);

            $table->dateTime('data_pagamento')->nullable()->index();
            $table->dateTime('data_movimento')->nullable()->index();
            $table->date('competencia')->nullable()->index();

            $table->text('observacoes')->nullable();

            $table->string('referencia_type', 190)->nullable()->index();
            $table->unsignedBigInteger('referencia_id')->nullable()->index();

            $table->string('pagamento_type', 190)->nullable()->index();
            $table->unsignedBigInteger('pagamento_id')->nullable()->index();

            $table->foreignId('created_by')->nullable()->index()
                ->constrained('acesso_usuarios')
                ->nullOnDelete()
                ->restrictOnUpdate();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['pagamento_type', 'pagamento_id'], 'ux_lf_pagamento');
            $table->index(['tipo', 'status', 'data_movimento'], 'ix_lf_tipo_status_data');

            $table->index(['referencia_type', 'referencia_id'], 'ix_lf_referencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lancamentos_financeiros');
    }
};
