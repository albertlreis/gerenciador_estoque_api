<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seed responsável por criar uma variação padrão para cada produto cadastrado.
 * Cada variação recebe preço, custo, SKU e código de barras únicos.
 */
class ProdutoVariacoesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $produtos = DB::table('produtos')->get();
        $variacoes = [];

        foreach ($produtos as $produto) {
            $variacoes[] = [
                'id_produto'    => $produto->id,
                'sku'           => "SKU-{$produto->id}-01",
                'nome'          => $produto->nome . ' - Variação Padrão',
                'preco'         => rand(50000, 300000) / 100.0,
                'custo'         => rand(30000, 250000) / 100.0,
                'codigo_barras' => '123456789012',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        DB::table('produto_variacoes')->insert($variacoes);
    }
}
