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
        $produtos = DB::table('produtos')->select('id', 'nome')->get();

        foreach ($produtos as $produto) {
            $qtdImagens = rand(1, 3);
            $imagens = [];

            for ($i = 1; $i <= $qtdImagens; $i++) {

                $imagens[] = [
                    'id_produto' => $produto->id,
                    'url' => "https://placehold.co/600x400?text=" . urlencode($produto->nome),
                    'principal' => $i === 1 ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('produto_imagens')->insert($imagens);
        }
    }
}
