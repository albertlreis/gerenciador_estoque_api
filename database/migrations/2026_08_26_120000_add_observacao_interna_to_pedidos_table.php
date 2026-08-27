<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->text('observacao_interna')
                ->nullable()
                ->after('observacoes')
                ->comment('Observação exclusiva para usuários do sistema; não deve constar em documentos externos.');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropColumn('observacao_interna');
        });
    }
};
