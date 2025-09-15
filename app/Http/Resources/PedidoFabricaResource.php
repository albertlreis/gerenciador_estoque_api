<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource de Pedido de Fábrica com itens, histórico de status, entregas e logs combinados.
 */
class PedidoFabricaResource extends JsonResource
{
    public function toArray($request): array
    {
        $itens       = PedidoFabricaItemResource::collection($this->whenLoaded('itens'));
        $historicos  = PedidoFabricaStatusHistoricoResource::collection($this->whenLoaded('historicos'));
        $entregas    = PedidoFabricaEntregaResource::collection($this->whenLoaded('entregas'));

        // Constrói "logs" no estilo dos chamados (mensagem + status_de/para + data)
        $logs = [];

        foreach ($this->whenLoaded('historicos') ?? [] as $h) {
            $logs[] = [
                'tipo'        => 'status',
                'mensagem'    => "Status alterado para {$h->status}",
                'status_de'   => null,
                'status_para' => $h->status,
                'created_at'  => $h->created_at?->toDateTimeString(),
            ];
        }

        foreach ($this->whenLoaded('entregas') ?? [] as $e) {
            $item = $this->itens->firstWhere('id', $e->pedido_fabrica_item_id);
            $produto = $item?->variacao?->nome_completo ?? ("Item #{$e->pedido_fabrica_item_id}");
            $depNome = $e->deposito?->nome ?? '—';
            $msg = "Entrega de {$e->quantidade} unid — {$produto} (Depósito: {$depNome})";
            $logs[] = [
                'tipo'        => 'entrega',
                'mensagem'    => $msg,
                'status_de'   => null,
                'status_para' => null,
                'created_at'  => $e->created_at?->toDateTimeString(),
            ];
        }

        // ordena por data
        usort($logs, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        return [
            'id'                    => $this->id,
            'status'                => $this->status,
            'data_previsao_entrega' => optional($this->data_previsao_entrega)?->toDateString(),
            'observacoes'           => $this->observacoes,
            'itens'                 => $itens,
            'historicos'            => $historicos,
            'entregas'              => $entregas,
            'logs'                  => $logs,
            'created_at'            => optional($this->created_at)?->toDateTimeString(),
        ];
    }
}
