<?php

namespace App\Services\Relatorios;

use App\Models\Consignacao;
use Illuminate\Support\Carbon;

class ConsignacaoRelatorioService
{
    /**
     * Lista consignações ativas com filtros opcionais.
     *
     * @param array $filtros cliente_id, parceiro_id, vencimento_ate
     * @return array
     */
    public function listarConsignacoesAtivas(array $filtros): array
    {
        $query = Consignacao::with(['pedido.cliente'])
            ->where('status', 'pendente')
            ->when(!empty($filtros['cliente_id']), fn($q) =>
            $q->whereHas('pedido', function ($query) use ($filtros) {
                $query->where('id_cliente', $filtros['cliente_id']);
            })
            )
            ->when(!empty($filtros['parceiro_id']), fn($q) =>
            $q->whereHas('pedido', function ($query) use ($filtros) {
                $query->where('id_parceiro', $filtros['parceiro_id']);
            })
            )
            ->when(!empty($filtros['vencimento_ate']), fn($q) =>
            $q->whereDate('prazo_resposta', '<=', Carbon::parse($filtros['vencimento_ate']))
            )
            ->orderBy('prazo_resposta');

        return $query->get()->map(function ($c) {
            $total = $c->pedido->itens
                ->where('id_variacao', $c->produto_variacao_id)
                ->sum('subtotal');

            return [
                'cliente'    => $c->pedido->cliente->nome ?? '-',
                'data_envio' => optional($c->data_envio)->format('Y-m-d'),
                'vencimento' => optional($c->prazo_resposta)->format('Y-m-d'),
                'status'     => $c->status,
                'total'      => $total,
            ];
        })->toArray();
    }
}
