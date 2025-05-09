<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutoVariacoesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $produtos = DB::table('produtos')->get();
        $variacoes = [];

        foreach ($produtos as $produto) {
            $num = rand(1, 3); // 1 a 3 variações por produto
            for ($i = 1; $i <= $num; $i++) {
                $variacoes[] = [
                    'produto_id'    => $produto->id,
                    'sku'           => "SKU-{$produto->id}-{$i}",
                    'nome'          => $produto->nome . " - Variação {$i}",
                    'preco'         => rand(50000, 300000) / 100.0,
                    'custo'         => rand(30000, 250000) / 100.0,
                    'codigo_barras' => rand(100000000000, 999999999999),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        DB::table('produto_variacoes')->insert($variacoes);
    }
}
