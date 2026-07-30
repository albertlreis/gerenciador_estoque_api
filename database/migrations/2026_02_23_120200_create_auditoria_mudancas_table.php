<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_mudancas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('evento_id');
            $table->string('field', 120);
            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->string('value_type', 20)->default('string');

            $table->foreign('evento_id')
                ->references('id')
                ->on('auditoria_eventos')
                ->cascadeOnDelete();

            $table->index('evento_id', 'idx_auditoria_mudancas_evento_id');
            $table->index('field', 'idx_auditoria_mudancas_field');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_mudancas');
    }
};
