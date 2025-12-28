<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_imagens', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_produto');

            // URL/path (texto longo)
            $table->text('url');

            $table->boolean('principal')->default(false);
            $table->timestamps();

            $table->index(['id_produto', 'principal'], 'idx_pi_produto_principal');

            $table->foreign('id_produto', 'produto_imagens_produto_fk')
                ->references('id')->on('produtos')
                ->cascadeOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_imagens');
    }
};
