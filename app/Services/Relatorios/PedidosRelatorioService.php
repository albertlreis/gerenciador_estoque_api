<?php

namespace App\Services\Relatorios;

use App\Models\Pedido;
use Illuminate\Support\Carbon;
use App\Enums\PedidoStatus;
use Throwable;


class PedidosRelatorioService
{
    /**
     * @param array $filtros data_inicio, data_fim, cliente_id, status, vendedor_id
     * @return array [array $dados, float $totalGeral]
     */
    public function listarPedidosPorPeriodo(array $filtros): array
    {
        $query = Pedido::with(['cliente', 'statusAtual'])
            ->when(!empty($filtros['data_inicio']), fn($q) =>
            $q->whereDate('data_pedido', '>=', Carbon::parse($filtros['data_inicio']))
            )
            ->when(!empty($filtros['data_fim']), fn($q) =>
            $q->whereDate('data_pedido', '<=', Carbon::parse($filtros['data_fim']))
            )
            ->when(!empty($filtros['cliente_id']), fn($q) =>
            $q->where('id_cliente', $filtros['cliente_id'])
            )
            ->when(!empty($filtros['vendedor_id']), fn($q) =>
            $q->where('id_usuario', $filtros['vendedor_id'])
            )
            ->when(!empty($filtros['status']), function ($q) use ($filtros) {
                $q->whereHas('statusAtual', function ($query) use ($filtros) {
                    $query->where('status', $filtros['status']);
                });
            })
            ->orderByDesc('data_pedido');

        $lista = $query->get();

        $dados = $lista->map(function ($pedido) {
            // Pode vir como enum, string ou nulo, dependendo do seu model/relationship
            $statusValue = $pedido->statusAtual->status ?? $pedido->status ?? null;

            // Resolve label de forma defensiva
            if ($statusValue instanceof PedidoStatus) {
                $statusLabel = $statusValue->label();
                $statusStr   = $statusValue->value;
            } elseif (is_string($statusValue) && $statusValue !== '') {
                try {
                    $statusEnum  = PedidoStatus::from($statusValue);
                    $statusLabel = $statusEnum->label();
                    $statusStr   = $statusEnum->value;
                } catch (Throwable) {
                    $statusLabel = ucfirst(str_replace('_', ' ', $statusValue)); // fallback legível
                    $statusStr   = $statusValue;
                }
            } else {
                $statusLabel = '-';
                $statusStr   = null;
            }

            return [
                'numero'       => $pedido->numero_externo ?? $pedido->id,
                'data'         => optional($pedido->data_pedido)->format('Y-m-d'),
                'data_br'      => optional($pedido->data_pedido)->format('d/m/Y'),
                'cliente'      => $pedido->cliente->nome ?? '-',
                'total'        => (float)($pedido->valor_total ?? 0),
                'status'       => $statusStr,      // mantém o "valor" se precisar em JSON
                'status_label' => $statusLabel,    // ✅ usado por PDF/Excel
            ];
        })->values()->toArray();

        $totalGeral = (float) $lista->sum('valor_total');

        return [$dados, $totalGeral];
    }
}
