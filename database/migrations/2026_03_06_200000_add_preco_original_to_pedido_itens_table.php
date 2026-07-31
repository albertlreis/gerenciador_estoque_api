<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->decimal('preco_original', 10, 2)->nullable()->after('quantidade');
        });

        DB::table('pedido_itens')->update([
            'preco_original' => DB::raw('preco_unitario'),
        ]);

        // Mantém compatibilidade com importações e rotinas legadas que ainda
        // inserem itens sem informar o preço original. O fluxo de pedidos atual
        // preenche o campo explicitamente.
    }

    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropColumn('preco_original');
        });
    }
};
