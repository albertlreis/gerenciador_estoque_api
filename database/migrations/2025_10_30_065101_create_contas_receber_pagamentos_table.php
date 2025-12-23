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
        Schema::create('contas_receber_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_receber_id')->constrained('contas_receber')->onDelete('cascade');
            $table->date('data_pagamento');
            $table->decimal('valor', 15);
            $table->string('forma_pagamento')->nullable();
            $table->string('comprovante_path')->nullable();
            $table->string('observacoes')->nullable();
            $table->unsignedInteger('usuario_id')->nullable();
            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->nullOnDelete();
            $table->unsignedInteger('usuario_id')->nullable();
            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('contas_receber_pagamentos');
    }
};
