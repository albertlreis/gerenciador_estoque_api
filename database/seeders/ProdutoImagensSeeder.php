<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutoImagensSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $produtos = DB::table('produtos')->pluck('id');

        $imagens = [];

        foreach ($produtos as $produtoId) {
            $qtd = rand(1, 3);

            for ($i = 1; $i <= $qtd; $i++) {
                $imagens[] = [
                    'id_produto' => $produtoId,
                    'url' => "https://placehold.co/600x400?text=Produto+{$produtoId}+Img{$i}",
                    'principal' => $i === 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('produto_imagens')->insert($imagens);
    }
}
