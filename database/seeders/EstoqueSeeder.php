<?php

namespace Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EstoqueSeeder extends Seeder
{
    /**
     * @throws \Exception
     */
    public function run(): void
    {
        $now = Carbon::now();

        $depositos = DB::table('depositos')->pluck('id')->toArray();
        $variacoes = DB::table('produto_variacoes')->pluck('id')->toArray();

        if (empty($depositos) || empty($variacoes)) {
            throw new Exception('Depósitos ou variações não encontradas.');
        }

        foreach ($variacoes as $idVariacao) {
            $quantidadeDepositos = rand(1, min(2, count($depositos)));
            $depositosSelecionados = collect($depositos)->shuffle()->take($quantidadeDepositos);

            foreach ($depositosSelecionados as $idDeposito) {
                DB::table('estoque')->insert([
                    'id_variacao' => $idVariacao,
                    'id_deposito' => $idDeposito,
                    'quantidade' => rand(5, 100),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
