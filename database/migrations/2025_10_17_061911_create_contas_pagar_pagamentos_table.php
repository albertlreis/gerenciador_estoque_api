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
        Schema::create('contas_pagar_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_pagar_id')->constrained('contas_pagar')->cascadeOnDelete();
            $table->date('data_pagamento');
            $table->decimal('valor', 15);
            $table->string('forma_pagamento', 30)->nullable();
            $table->string('comprovante_path')->nullable();
            $table->text('observacoes')->nullable();
            $table->unsignedInteger('usuario_id')->nullable();
            $table->unsignedBigInteger('conta_financeira_id')->nullable()->after('usuario_id');
            $table->timestamps();

            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->onDelete('set null');
            $table->foreign('conta_financeira_id')->references('id')->on('contas_financeiras')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('contas_pagar_pagamentos');
    }
};
