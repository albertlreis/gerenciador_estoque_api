<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_mudancas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evento_id');
            $table->string('campo', 120);
            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->string('value_type', 40)->default('string');
            $table->timestamps();

            $table->index(['evento_id', 'campo'], 'idx_auditoria_mudancas_evento_campo');

            $table->foreign('evento_id')
                ->references('id')
                ->on('auditoria_eventos')
                ->cascadeOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_mudancas');
    }
};
