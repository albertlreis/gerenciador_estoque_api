<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PedidosFabricaSeeder extends Seeder
{
    public function run(): void
    {
        $variacoes = DB::table('produto_variacoes')->pluck('id')->toArray();
        $pedidosVenda = DB::table('pedidos')->pluck('id')->toArray();
        $depositos = DB::table('depositos')->pluck('id')->toArray();

        if (empty($variacoes) || empty($depositos)) {
            $this->command->warn('Variacoes ou Depositos não encontrados. Seeder abortada.');
            return;
        }

        $statusPossiveis = ['pendente', 'produzindo', 'entregue', 'cancelado'];
        $observacoesPool = [
            'Reposição de giro rápido.',
            'Cliente pediu para entregar mês que vem.',
            'Pedido especial com acabamento personalizado.',
            'Encomenda urgente para showroom.',
            'Reforço de estoque para Black Friday.',
            null
        ];

        $agora = now();

        for ($i = 0; $i < 15; $i++) {
            $status = fake()->randomElement($statusPossiveis);
            $dataPrevisao = fake()->optional()->dateTimeBetween('+5 days', '+40 days');

            $pedidoId = DB::table('pedidos_fabrica')->insertGetId([
                'status' => $status,
                'data_previsao_entrega' => $dataPrevisao ? Carbon::parse($dataPrevisao)->format('Y-m-d') : null,
                'observacoes' => fake()->randomElement($observacoesPool),
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $itens = collect($variacoes)
                ->shuffle()
                ->take(rand(1, 3))
                ->map(function ($idVariacao) use ($pedidoId, $pedidosVenda, $depositos, $agora) {
                    return [
                        'pedido_fabrica_id' => $pedidoId,
                        'produto_variacao_id' => $idVariacao,
                        'quantidade' => rand(1, 10),
                        'deposito_id' => fake()->randomElement($depositos),
                        'pedido_venda_id' => fake()->optional(0.5)->randomElement($pedidosVenda),
                        'observacoes' => fake()->optional()->sentence(),
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];
                });

            DB::table('pedidos_fabrica_itens')->insert($itens->toArray());
        }
    }
}
