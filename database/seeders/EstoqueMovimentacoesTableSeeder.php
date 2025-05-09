<?php

namespace Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use App\Models\EstoqueMovimentacao;
use App\Models\ProdutoVariacao;
use Carbon\Carbon;

class EstoqueMovimentacoesTableSeeder extends Seeder
{
    public function run()
    {
        $variacoes = ProdutoVariacao::take(20)->get();

        if ($variacoes->count() < 10) {
            throw new Exception('É necessário pelo menos 10 variações de produto para popular as movimentações.');
        }

        foreach ($variacoes as $index => $variacao) {
            EstoqueMovimentacao::create([
                'id_variacao' => $variacao->id,
                'id_deposito_origem' => $index % 2 === 0 ? 1 : null,
                'id_deposito_destino' => $index % 2 === 0 ? 2 : 1,
                'tipo' => $index % 2 === 0 ? 'transferencia' : 'entrada',
                'quantidade' => rand(5, 50),
                'observacao' => $index % 2 === 0 ? 'Transferência gerada em seed' : 'Entrada gerada em seed',
                'data_movimentacao' => Carbon::now()->subDays(rand(1, 30)),
            ]);
        }
    }
}
