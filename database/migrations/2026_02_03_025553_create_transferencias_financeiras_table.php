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
        Schema::create('transferencias_financeiras', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('conta_origem_id');
            $table->unsignedBigInteger('conta_destino_id');

            $table->decimal('valor', 15, 2);

            $table->dateTime('data_movimento');

            $table->text('observacoes')->nullable();

            $table->string('status', 20)->default('confirmado');

            $table->unsignedBigInteger('created_by')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'data_movimento'], 'ix_tf_status_data');
            $table->index(['conta_origem_id', 'data_movimento'], 'ix_tf_origem_data');
            $table->index(['conta_destino_id', 'data_movimento'], 'ix_tf_destino_data');

            $table->foreign('conta_origem_id')
                ->references('id')->on('contas_financeiras')
                ->onDelete('restrict')->onUpdate('restrict');

            $table->foreign('conta_destino_id')
                ->references('id')->on('contas_financeiras')
                ->onDelete('restrict')->onUpdate('restrict');

            $table->foreign('created_by')
                ->references('id')->on('acesso_usuarios')
                ->onDelete('set null')->onUpdate('restrict');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('transferencias_financeiras');
    }
};
