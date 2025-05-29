<?php

namespace Database\Seeders;

use App\Models\Consignacao;
use App\Models\Pedido;
use App\Models\ProdutoVariacao;
use Illuminate\Database\Seeder;

class ConsignacaoSeeder extends Seeder
{
    public function run(): void
    {
        $pedidos = Pedido::inRandomOrder()->take(5)->get();

        foreach ($pedidos as $pedido) {
            $variacoes = ProdutoVariacao::inRandomOrder()->take(rand(1, 3))->get();

            foreach ($variacoes as $variacao) {
                Consignacao::create([
                    'pedido_id' => $pedido->id,
                    'produto_variacao_id' => $variacao->id,
                    'quantidade' => rand(1, 5),
                    'data_envio' => now()->subDays(rand(1, 5)),
                    'prazo_resposta' => now()->addDays(rand(2, 10)),
                    'status' => 'pendente',
                ]);
            }
        }
    }
}
