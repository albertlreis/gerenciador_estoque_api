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

        DB::statement('ALTER TABLE pedido_itens MODIFY preco_original DECIMAL(10,2) NOT NULL');
    }

    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropColumn('preco_original');
        });
    }
};
