<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque_imports', function (Blueprint $table) {
            $table->id();

            $table->string('arquivo_nome');
            $table->string('arquivo_hash')->unique();

            $table->unsignedInteger('usuario_id')->nullable();

            $table->enum('status', ['pendente','processando','concluido','com_erro','cancelado'])
                ->default('pendente');

            $table->unsignedInteger('linhas_total')->default(0);
            $table->unsignedInteger('linhas_processadas')->default(0);
            $table->unsignedInteger('linhas_validas')->default(0);
            $table->unsignedInteger('linhas_invalidas')->default(0);

            $table->json('metricas')->nullable();
            $table->text('mensagem')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->foreign('usuario_id', 'estoque_imports_usuario_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_imports');
    }
};
