<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('origem_abastecimento', 30)
                ->default('estoque')
                ->after('tipo')
                ->index();
        });

        DB::table('pedidos')
            ->where('tipo', 'reposicao')
            ->orWhereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('pedido_importacoes')
                    ->whereColumn('pedido_importacoes.pedido_id', 'pedidos.id');
            })
            ->update(['origem_abastecimento' => 'fabrica']);
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex(['origem_abastecimento']);
            $table->dropColumn('origem_abastecimento');
        });
    }
};
