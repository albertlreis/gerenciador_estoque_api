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

        $variacoes = DB::table('produto_variacoes')
            ->join('produtos', 'produto_variacoes.produto_id', '=', 'produtos.id')
            ->select('produto_variacoes.id as variacao_id', 'produtos.is_outlet')
            ->get();

        if ($variacoes->isEmpty()) {
            throw new Exception('Variações não encontradas.');
        }

        // Separa outlet e não-outlet
        $variacoesOutlet = $variacoes->where('is_outlet', true);
        $variacoesNormais = $variacoes->where('is_outlet', false);

        // Define % das variações normais que ficarão SEM estoque
        $percentualSemEstoque = 15;
        $quantidadeSemEstoque = intval($variacoesNormais->count() * $percentualSemEstoque / 100);
        $variacoesSemEstoque = $variacoesNormais->shuffle()->take($quantidadeSemEstoque);
        $variacoesComEstoque = $variacoesNormais
            ->filter(fn ($v) => !$variacoesSemEstoque->contains('variacao_id', $v->variacao_id))
            ->merge($variacoesOutlet);

        foreach ($variacoesComEstoque as $v) {
            $quantidadeDepositos = rand(1, min(2, count($depositos)));
            $depositosSelecionados = collect($depositos)->shuffle()->take($quantidadeDepositos);

            foreach ($depositosSelecionados as $idDeposito) {
                DB::table('estoque')->updateOrInsert([
                    'id_variacao' => $v->variacao_id,
                    'id_deposito' => $idDeposito,
                    'quantidade' => rand(5, 100),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
