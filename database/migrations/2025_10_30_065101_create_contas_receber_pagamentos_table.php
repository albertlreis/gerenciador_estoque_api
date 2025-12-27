<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_receber_pagamentos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conta_receber_id')
                ->constrained('contas_receber')
                ->cascadeOnDelete();

            $table->date('data_pagamento')->index();
            $table->decimal('valor', 15, 2);
            $table->string('forma_pagamento', 30);

            $table->string('comprovante_path')->nullable();
            $table->text('observacoes')->nullable();

            $table->unsignedInteger('usuario_id')->nullable()->index();
            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->nullOnDelete();

            $table->foreignId('conta_financeira_id')
                ->constrained('contas_financeiras')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['conta_receber_id', 'data_pagamento'], 'ix_crp_conta_data');
            $table->index(['conta_financeira_id', 'data_pagamento'], 'ix_crp_cf_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_receber_pagamentos');
    }
};
