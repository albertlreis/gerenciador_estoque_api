<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Se existir algo legado, remove (sem legado)
        Schema::dropIfExists('localizacoes_estoque');

        Schema::create('localizacoes_estoque', function (Blueprint $table) {
            // OBS: $table->id() cria BIGINT UNSIGNED (ok)
            $table->id();

            /**
             * IMPORTANTE:
             * - estoque.id = INT UNSIGNED (vide seu DDL)
             * - portanto, estoque_id DEVE ser INT UNSIGNED, não BIGINT
             */
            $table->unsignedInteger('estoque_id');

            // Campos essenciais (podem ser nulos)
            $table->string('setor', 10)->nullable();
            $table->string('coluna', 10)->nullable();
            $table->string('nivel', 10)->nullable();

            /**
             * areas_estoque.id foi criado com $table->id() -> BIGINT UNSIGNED
             * então area_id DEVE ser unsignedBigInteger
             */
            $table->unsignedBigInteger('area_id')->nullable();

            $table->string('codigo_composto', 100)->nullable()->index(); // ex.: "6-B1"
            $table->text('observacoes')->nullable();

            $table->timestamps();

            // FK para estoque (tabela singular 'estoque' conforme seu DDL)
            $table->foreign('estoque_id')
                ->references('id')
                ->on('estoque')
                ->onDelete('cascade');

            // FK para áreas
            $table->foreign('area_id')
                ->references('id')
                ->on('areas_estoque')
                ->nullOnDelete();

            // 1:1 entre item de estoque e localização
            $table->unique('estoque_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('localizacoes_estoque');
    }
};
