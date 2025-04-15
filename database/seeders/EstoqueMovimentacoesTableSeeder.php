<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EstoqueMovimentacao;
use Carbon\Carbon;

class EstoqueMovimentacoesTableSeeder extends Seeder
{
    public function run()
    {
        // Exemplo: Transferência do Produto 1 do Depósito 1 para o Depósito 2
        EstoqueMovimentacao::create([
            'id_produto'           => 1,
            'id_deposito_origem'   => 1,
            'id_deposito_destino'  => 2,
            'tipo'                 => 'transferencia',
            'quantidade'           => 20,
            'observacao'           => 'Transferência interna',
            'data_movimentacao'    => Carbon::now(),
        ]);

        // Exemplo: Entrada de estoque para o Produto 2 no Depósito 1 (compra de estoque)
        EstoqueMovimentacao::create([
            'id_produto'           => 2,
            'id_deposito_origem'   => null,
            'id_deposito_destino'  => 1,
            'tipo'                 => 'entrada',
            'quantidade'           => 50,
            'observacao'           => 'Entrada de estoque - compra',
            'data_movimentacao'    => Carbon::now()->subDays(2),
        ]);
    }
}
