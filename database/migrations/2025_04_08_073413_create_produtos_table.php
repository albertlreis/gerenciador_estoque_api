<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de produtos base (ex: "Mesa de Jantar").
     * Os dados dimensionais são fixos por produto.
     */
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->increments('id')->comment('Identificador do produto');

            $table->string('nome', 255)->comment('Nome do produto');
            $table->text('descricao')->nullable()->comment('Descrição opcional do produto');

            $table->unsignedInteger('id_categoria')->comment('Categoria do produto');
            $table->unsignedInteger('id_fornecedor')->nullable()->comment('Fornecedor do produto');

            $table->decimal('altura', 10, 2)->nullable()->comment('Altura em centímetros');
            $table->decimal('largura', 10, 2)->nullable()->comment('Largura em centímetros');
            $table->decimal('profundidade', 10, 2)->nullable()->comment('Profundidade em centímetros');
            $table->decimal('peso', 10, 2)->nullable()->comment('Peso em quilos');

            // consolidado
            $table->string('manual_conservacao', 255)->nullable()
                ->comment('Hash/filename do manual de conservação (PDF)');
            $table->unsignedInteger('estoque_minimo')->nullable();

            $table->boolean('ativo')->default(true)->comment('Produto está ativo no sistema');
            $table->text('motivo_desativacao')->nullable();

            $table->timestamps();

            $table->index('nome');
            $table->index('id_categoria', 'idx_produtos_categoria');

            // FKs: recomendo NÃO cascata em categoria (evita apagar produtos por acidente)
            $table->foreign('id_categoria', 'produtos_categoria_fk')
                ->references('id')->on('categorias')
                ->onDelete('restrict')
                ->onUpdate('restrict');

            $table->foreign('id_fornecedor', 'produtos_fornecedor_fk')
                ->references('id')->on('fornecedores')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });

        // FULLTEXT (mantendo compatibilidade; se não suportar, a migração pode falhar)
        // Se seu ambiente é MySQL 8+ (InnoDB), ok.
        DB::statement('ALTER TABLE `produtos` ADD FULLTEXT INDEX `ft_produtos_nome` (`nome`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('produtos');
    }
};
