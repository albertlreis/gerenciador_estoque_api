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

        // Fallback para usuário administrador
        $adminId = DB::table('acesso_usuarios')->whereIn('email', ['admin@teste.com'])->value('id');

        $itensPedidos = DB::table('pedido_itens')
            ->whereIn('id_pedido', $pedidosConsignados)
            ->get();

        $statusPossiveis = ['pendente', 'comprado', 'devolvido'];
        $consignacoes = [];
        $movimentacoes = [];

        // 1ª Etapa: inserir consignações
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
                'data_resposta' => $dataResposta?->toDateString(),
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('consignacoes')->insert($consignacoes);

        // Recuperar IDs das consignações inseridas
        $consignacoesInseridas = DB::table('consignacoes')
            ->orderByDesc('id')
            ->limit(count($consignacoes))
            ->get()
            ->reverse()
            ->values();

        $devolucoes = [];

        // 2ª Etapa: movimentações e devoluções
        foreach ($consignacoesInseridas as $i => $consignacao) {
            $item = $itensPedidos[$i];
            $status = $consignacao->status;
            $dataEnvio = Carbon::parse($consignacao->data_envio);
            $dataResposta = $consignacao->data_resposta ? Carbon::parse($consignacao->data_resposta) : null;

            $estoqueOrigem = DB::table('estoque')
                ->where('id_variacao', $item->id_variacao)
                ->orderByDesc('quantidade')
                ->first();

            if (!$estoqueOrigem || $estoqueOrigem->quantidade < $item->quantidade) {
                continue;
            }

            // Envio
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

            // Devolução
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

                $usuarioResponsavel = $usuariosPedidos[$consignacao->pedido_id] ?? $adminId;

                $devolucoes[] = [
                    'consignacao_id' => $consignacao->id,
                    'quantidade' => $item->quantidade,
                    'observacoes' => 'Devolução automática de seed',
                    'usuario_id' => $usuarioResponsavel,
                    'data_devolucao' => $dataResposta,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Compra
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

        DB::table('estoque_movimentacoes')->insert($movimentacoes);

        if (!empty($devolucoes)) {
            DB::table('consignacao_devolucoes')->insert($devolucoes);
        }
    }
}
