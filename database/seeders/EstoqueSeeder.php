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

        // Variações em outlet ativo
        $variacoesOutlet = DB::table('produto_variacao_outlets')
            ->where('quantidade_restante', '>', 0)
            ->pluck('produto_variacao_id')
            ->unique()
            ->toArray();

        // Variações normais (não estão em outlet ativo)
        $variacoesNormais = array_diff($variacoes, $variacoesOutlet);

        // 15% sem estoque
        $percentualSemEstoque = 15;
        $quantidadeSemEstoque = intval(count($variacoesNormais) * $percentualSemEstoque / 100);

        $variacoesSemEstoque = collect($variacoesNormais)->shuffle()->take($quantidadeSemEstoque)->toArray();

        $variacoesComEstoque = collect($variacoes)
            ->filter(fn($id) => !in_array($id, $variacoesSemEstoque));

        foreach ($variacoesComEstoque as $idVariacao) {
            $quantidadeDepositos = rand(1, min(2, count($depositos)));
            $depositosSelecionados = collect($depositos)->shuffle()->take($quantidadeDepositos);

            foreach ($depositosSelecionados as $idDeposito) {
                DB::table('estoque')->updateOrInsert([
                    'id_variacao' => $idVariacao,
                    'id_deposito' => $idDeposito,
                ], [
                    'quantidade' => rand(5, 100),
                    'corredor' => chr(rand(65, 70)), // A-F
                    'prateleira' => (string) rand(1, 5),
                    'nivel' => (string) rand(1, 3),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
