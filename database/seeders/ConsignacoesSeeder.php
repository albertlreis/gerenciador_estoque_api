<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ConsignacoesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Buscar os pedidos cujo último status registrado foi 'consignado'
        $pedidosConsignados = DB::table('pedido_status_historico as h')
            ->select('h.pedido_id')
            ->join(DB::raw('(SELECT pedido_id, MAX(data_status) as max_data FROM pedido_status_historico GROUP BY pedido_id) as ultimos'), function ($join) {
                $join->on('h.pedido_id', '=', 'ultimos.pedido_id')
                    ->on('h.data_status', '=', 'ultimos.max_data');
            })
            ->where('h.status', 'consignado')
            ->pluck('h.pedido_id');

        if ($pedidosConsignados->isEmpty()) {
            return;
        }

        $itensPedidos = DB::table('pedido_itens')
            ->whereIn('id_pedido', $pedidosConsignados)
            ->get();

        $statusPossiveis = ['pendente', 'comprado', 'devolvido'];
        $consignacoes = [];
        $movimentacoes = [];

        foreach ($itensPedidos as $item) {
            $dataEnvio = Carbon::now()->subDays(rand(10, 30));
            $prazo = (clone $dataEnvio)->addDays(rand(7, 15));
            $status = fake()->randomElement($statusPossiveis);
            $dataResposta = in_array($status, ['comprado', 'devolvido']) ? $prazo->copy()->addDays(rand(0, 5)) : null;

            $consignacoes[] = [
                'pedido_id' => $item->id_pedido,
                'produto_variacao_id' => $item->id_variacao,
                'quantidade' => $item->quantidade,
                'data_envio' => $dataEnvio->toDateString(),
                'prazo_resposta' => $prazo->toDateString(),
                'data_resposta' => $dataResposta,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $estoqueOrigem = DB::table('estoque')
                ->where('id_variacao', $item->id_variacao)
                ->orderByDesc('quantidade')
                ->first();

            if ($estoqueOrigem && $estoqueOrigem->quantidade >= $item->quantidade) {
                $movimentacoes[] = [
                    'id_variacao' => $item->id_variacao,
                    'id_deposito_origem' => $estoqueOrigem->id_deposito,
                    'id_deposito_destino' => null,
                    'tipo' => 'consignacao_envio',
                    'quantidade' => $item->quantidade,
                    'observacao' => 'Produto enviado para consignação',
                    'data_movimentacao' => $dataEnvio,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                DB::table('estoque')
                    ->where('id', $estoqueOrigem->id)
                    ->decrement('quantidade', $item->quantidade);

                if ($status === 'devolvido') {
                    DB::table('estoque')->updateOrInsert(
                        [
                            'id_variacao' => $item->id_variacao,
                            'id_deposito' => $estoqueOrigem->id_deposito,
                        ],
                        [
                            'quantidade' => DB::raw('quantidade + ' . $item->quantidade),
                            'updated_at' => $now,
                        ]
                    );

                    $movimentacoes[] = [
                        'id_variacao' => $item->id_variacao,
                        'id_deposito_origem' => null,
                        'id_deposito_destino' => $estoqueOrigem->id_deposito,
                        'tipo' => 'consignacao_devolucao',
                        'quantidade' => $item->quantidade,
                        'observacao' => 'Produto devolvido de consignação',
                        'data_movimentacao' => $dataResposta,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($status === 'comprado') {
                    $movimentacoes[] = [
                        'id_variacao' => $item->id_variacao,
                        'id_deposito_origem' => null,
                        'id_deposito_destino' => null,
                        'tipo' => 'consignacao_compra',
                        'quantidade' => $item->quantidade,
                        'observacao' => 'Produto consignado convertido em venda',
                        'data_movimentacao' => $dataResposta,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::table('consignacoes')->insert($consignacoes);
        DB::table('estoque_movimentacoes')->insert($movimentacoes);
    }
}
