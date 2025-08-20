<?php

namespace App\Services\Relatorios;

use App\Models\Consignacao;
use Illuminate\Support\Carbon;

class ConsignacaoRelatorioService
{
    /**
     * Filtros aceitos:
     * - status: string (pendente, comprado, devolvido, parcial, vencido)
     * - envio_inicio, envio_fim (YYYY-MM-DD)
     * - vencimento_inicio, vencimento_fim (YYYY-MM-DD)
     * - consolidado: bool (true => agrupar por cliente)
     *
     * @return array [array $linhas, float $totalGeral, bool $consolidado]
     */
    public function listarConsignacoes(array $filtros): array
    {
        $query = Consignacao::with([
            'pedido.cliente',
            'pedido.itens.variacao.produto',
        ]);

        // STATUS
        if (!empty($filtros['status'])) {
            $query->where('status', $filtros['status']);
        }

        // Período de ENVIO
        if (!empty($filtros['envio_inicio'])) {
            $query->whereDate('data_envio', '>=', Carbon::parse($filtros['envio_inicio']));
        }
        if (!empty($filtros['envio_fim'])) {
            $query->whereDate('data_envio', '<=', Carbon::parse($filtros['envio_fim']));
        }

        // Período de VENCIMENTO
        if (!empty($filtros['vencimento_inicio'])) {
            $query->whereDate('prazo_resposta', '>=', Carbon::parse($filtros['vencimento_inicio']));
        }
        if (!empty($filtros['vencimento_fim'])) {
            $query->whereDate('prazo_resposta', '<=', Carbon::parse($filtros['vencimento_fim']));
        }

        $query->orderBy('prazo_resposta');

        $lista = $query->get();

        // Mapeamento de linhas detalhadas
        $detalhado = $lista->flatMap(function ($c) {
            $cliente = $c->pedido->cliente->nome ?? '-';
            $status  = $c->status ?: '-';

            // valor total do(s) item(ns) da mesma variação nesta consignação
            $total = (float) $c->pedido->itens
                ->where('id_variacao', $c->produto_variacao_id)
                ->sum('subtotal');

            // nome do produto
            $produto = optional($c->pedido->itens
                ->firstWhere('id_variacao', $c->produto_variacao_id))
                ?->variacao?->produto?->nome ?? '-';

            return [[
                'cliente'       => $cliente,
                'produto'       => $produto,
                'data_envio'    => optional($c->data_envio)->format('Y-m-d'),
                'data_envio_br' => optional($c->data_envio)->format('d/m/Y'),
                'vencimento'    => optional($c->prazo_resposta)->format('Y-m-d'),
                'vencimento_br' => optional($c->prazo_resposta)->format('d/m/Y'),
                'status'        => $status,
                'status_label'  => ucfirst($status),
                'total'         => $total,
            ]];
        })->values()->toArray();

        $totalGeral = (float) array_sum(array_column($detalhado, 'total'));
        $consolidado = filter_var($filtros['consolidado'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($consolidado) {
            // Agrupar por cliente somando total
            $porCliente = [];
            foreach ($detalhado as $linha) {
                $cli = $linha['cliente'] ?? '-';
                $porCliente[$cli] = ($porCliente[$cli] ?? 0) + (float)$linha['total'];
            }
            $linhas = [];
            foreach ($porCliente as $cliente => $valor) {
                $linhas[] = [
                    'cliente' => $cliente,
                    'total'   => (float)$valor,
                ];
            }
            usort($linhas, fn($a, $b) => strcasecmp($a['cliente'], $b['cliente']));
            return [$linhas, $totalGeral, true];
        }

        // Detalhado
        return [$detalhado, $totalGeral, false];
    }
}
