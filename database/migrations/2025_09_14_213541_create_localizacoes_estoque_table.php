<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('localizacoes_estoque', function (Blueprint $table) {
            $table->id();

            // estoque.id = increments() => unsignedInteger
            $table->unsignedInteger('estoque_id');

            $table->string('setor', 10)->nullable();
            $table->string('coluna', 10)->nullable();
            $table->string('nivel', 10)->nullable();

            // areas_estoque.id = bigInt (id())
            $table->unsignedBigInteger('area_id')->nullable();

            $table->string('codigo_composto', 100)->nullable()->index();
            $table->text('observacoes')->nullable();

            $table->timestamps();

            $table->foreign('estoque_id', 'loc_est_estoque_fk')
                ->references('id')->on('estoque')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('area_id', 'loc_est_area_fk')
                ->references('id')->on('areas_estoque')
                ->nullOnDelete()
                ->onUpdate('restrict');

            // 1:1
            $table->unique('estoque_id', 'loc_est_uq_estoque');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('localizacoes_estoque');
    }
};
