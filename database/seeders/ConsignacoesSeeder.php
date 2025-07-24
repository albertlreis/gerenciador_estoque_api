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

        // Mapear usuários responsáveis pelos pedidos
        $usuariosPedidos = DB::table('pedidos')
            ->select('id', 'id_usuario')
            ->whereIn('id', $pedidosConsignados)
            ->pluck('id_usuario', 'id');

        $adminId = DB::table('acesso_usuarios')
            ->where('email', 'admin@teste.com')
            ->value('id');

        $itensPedidos = DB::table('pedido_itens')
            ->whereIn('id_pedido', $pedidosConsignados)
            ->get();

        $statusPossiveis = ['pendente', 'comprado', 'devolvido'];
        $consignacoes = [];
        $movimentacoes = [];
        $devolucoes = [];

        foreach ($itensPedidos as $item) {
            $estoque = DB::table('estoque')
                ->where('id_variacao', $item->id_variacao)
                ->orderByDesc('quantidade')
                ->first();

            if (!$estoque || $estoque->quantidade < $item->quantidade) {
                continue;
            }

            $dataEnvio = now()->subDays(rand(10, 30));
            $prazo = (clone $dataEnvio)->addDays(rand(7, 15));
            $status = fake()->randomElement($statusPossiveis);
            $dataResposta = in_array($status, ['comprado', 'devolvido']) ? $prazo->copy()->addDays(rand(0, 5)) : null;

            // Inserir consignação
            $consignacaoId = DB::table('consignacoes')->insertGetId([
                'pedido_id' => $item->id_pedido,
                'produto_variacao_id' => $item->id_variacao,
                'deposito_id' => $estoque->id_deposito,
                'quantidade' => $item->quantidade,
                'data_envio' => $dataEnvio,
                'prazo_resposta' => $prazo,
                'data_resposta' => $dataResposta,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Envio
            $movimentacoes[] = [
                'id_variacao' => $item->id_variacao,
                'id_deposito_origem' => $estoque->id_deposito,
                'id_deposito_destino' => null,
                'tipo' => 'consignacao_envio',
                'quantidade' => $item->quantidade,
                'observacao' => 'Produto enviado para consignação',
                'data_movimentacao' => $dataEnvio,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            DB::table('estoque')
                ->where('id', $estoque->id)
                ->decrement('quantidade', $item->quantidade);

            // Devolução
            if ($status === 'devolvido') {
                DB::table('estoque')->updateOrInsert(
                    [
                        'id_variacao' => $item->id_variacao,
                        'id_deposito' => $estoque->id_deposito,
                    ],
                    [
                        'quantidade' => DB::raw('quantidade + ' . $item->quantidade),
                        'updated_at' => $now,
                    ]
                );

                $movimentacoes[] = [
                    'id_variacao' => $item->id_variacao,
                    'id_deposito_origem' => null,
                    'id_deposito_destino' => $estoque->id_deposito,
                    'tipo' => 'consignacao_devolucao',
                    'quantidade' => $item->quantidade,
                    'observacao' => 'Produto devolvido de consignação',
                    'data_movimentacao' => $dataResposta,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $usuarioResponsavel = $usuariosPedidos[$item->id_pedido] ?? $adminId;

                $devolucoes[] = [
                    'consignacao_id' => $consignacaoId,
                    'quantidade' => $item->quantidade,
                    'observacoes' => 'Devolução automática de seed',
                    'usuario_id' => $usuarioResponsavel,
                    'data_devolucao' => $dataResposta,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('estoque_movimentacoes')->insert($movimentacoes);

        if (!empty($devolucoes)) {
            DB::table('consignacao_devolucoes')->insert($devolucoes);
        }
    }
}
