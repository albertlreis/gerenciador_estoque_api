<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiro_contatos', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parceiro_id');
            $table->string('tipo', 20);
            $table->string('valor', 255);
            $table->string('valor_e164', 20)->nullable();
            $table->string('rotulo', 50)->nullable();
            $table->boolean('principal')->default(false);
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parceiro_id', 'idx_parceiro_contatos_parceiro_id');
            $table->index('tipo', 'idx_parceiro_contatos_tipo');
            $table->index('valor', 'idx_parceiro_contatos_valor');

            $table->foreign('parceiro_id', 'fk_parceiro_contatos_parceiro_id')
                ->references('id')
                ->on('parceiros')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiro_contatos');
    }
};
