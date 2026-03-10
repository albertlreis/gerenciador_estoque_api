<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_conjuntos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome', 255);
            $table->text('descricao')->nullable();
            $table->string('hero_image_path', 255)->nullable();
            $table->enum('preco_modo', ['soma', 'individual', 'apartir'])->default('soma');
            $table->unsignedInteger('principal_variacao_id')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index('ativo', 'idx_pc_ativo');
            $table->index('preco_modo', 'idx_pc_preco_modo');
            $table->index('principal_variacao_id', 'idx_pc_principal_variacao');

            $table->foreign('principal_variacao_id', 'pc_principal_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });

        Schema::create('produto_conjunto_itens', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('produto_conjunto_id');
            $table->unsignedInteger('produto_variacao_id');
            $table->string('label', 80)->nullable();
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();

            $table->index(['produto_conjunto_id', 'ordem'], 'idx_pci_conjunto_ordem');
            $table->index('produto_variacao_id', 'idx_pci_variacao');

            $table->foreign('produto_conjunto_id', 'pci_conjunto_fk')
                ->references('id')->on('produto_conjuntos')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('produto_variacao_id', 'pci_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->cascadeOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_conjunto_itens');
        Schema::dropIfExists('produto_conjuntos');
    }
};
