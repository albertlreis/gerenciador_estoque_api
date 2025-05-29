<?php

namespace Database\Seeders;

use App\Models\Consignacao;
use App\Models\Pedido;
use App\Models\ProdutoVariacao;
use Illuminate\Database\Seeder;

class ConsignacoesVencendoSeeder extends Seeder
{
    public function run(): void
    {
        $pedido = Pedido::inRandomOrder()->first();
        $variacoes = ProdutoVariacao::inRandomOrder()->take(3)->get();

        foreach ($variacoes as $variacao) {
            Consignacao::create([
                'pedido_id' => $pedido->id,
                'produto_variacao_id' => $variacao->id,
                'quantidade' => rand(1, 5),
                'data_envio' => now()->subDays(5),
                'prazo_resposta' => now()->addDays(rand(1, 2)),
                'status' => 'pendente',
            ]);
        }
    }
}
