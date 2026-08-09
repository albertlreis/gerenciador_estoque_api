<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_importacoes', function (Blueprint $table) {
            $table->string('arquivo_path')->nullable()->after('arquivo_hash');
            $table->char('arquivo_hash_conteudo', 64)->nullable()->after('arquivo_path');
            $table->unsignedBigInteger('arquivo_tamanho')->nullable()->after('arquivo_hash_conteudo');
            $table->string('arquivo_mime', 120)->nullable()->after('arquivo_tamanho');
            $table->timestamp('arquivo_salvo_at')->nullable()->after('arquivo_mime');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_importacoes', function (Blueprint $table) {
            $table->dropColumn([
                'arquivo_path',
                'arquivo_hash_conteudo',
                'arquivo_tamanho',
                'arquivo_mime',
                'arquivo_salvo_at',
            ]);
        });
    }
};
