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
        if (empty($depositos)) {
            throw new Exception('Depósitos não encontrados.');
        }

        // Todas as variações
        $variacoes = DB::table('produto_variacoes')->pluck('id')->toArray();
        if (empty($variacoes)) {
            throw new Exception('Variações não encontradas.');
        }

        foreach ($variacoes as $indiceVariacao => $idVariacao) {
            foreach (array_slice($depositos, 0, min(2, count($depositos))) as $indiceDeposito => $idDeposito) {
                DB::table('estoque')->updateOrInsert([
                    'id_variacao' => $idVariacao,
                    'id_deposito' => $idDeposito,
                ], [
                    'quantidade' => 10 + (($indiceVariacao + 1) * 2) + $indiceDeposito,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
