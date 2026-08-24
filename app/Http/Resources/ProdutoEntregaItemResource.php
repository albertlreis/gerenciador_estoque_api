<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoEntregaItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $entregas = app(\App\Services\EntregaProdutoService::class);
        $total = (int) $this->quantidade_total;
        $recebida = (int) $this->quantidade_recebida;
        $expedida = (int) $this->quantidade_expedida;
        $entregue = (int) $this->quantidade_entregue;
        $atendido = max((int) $this->quantidade_reservada, $expedida, $entregue);
        $aguardaFabrica = $this->pedido
            && $this->pedido->origem_abastecimento === \App\Models\Pedido::ORIGEM_ABASTECIMENTO_FABRICA;
        $parcial = ($recebida > 0 && $recebida < $total)
            || ($expedida > 0 && $expedida < $total)
            || ($entregue > 0 && $entregue < $total);
        $etapaOperacional = match (true) {
            $this->em_revisao => 'divergencia',
            $this->status === \App\Models\ProdutoEntregaItem::STATUS_CANCELADO => 'cancelado',
            $entregue >= $total && $total > 0 => 'entregue_cliente',
            $entregue > 0 => 'entrega_parcial',
            $expedida > 0 => 'em_entrega',
            $recebida >= $total && $total > 0 => 'recebido_estoque',
            $recebida > 0 => 'recebimento_parcial',
            $aguardaFabrica => 'aguardando_fabrica',
            $this->id_deposito_destino !== null => 'aguardando_fabrica',
            default => 'aguardando_estoque',
        };
        $proximaAcao = match ($etapaOperacional) {
            'aguardando_fabrica', 'recebimento_parcial' => 'registrar_recebimento_estoque',
            'aguardando_estoque' => 'reservar_estoque_atual',
            'recebido_estoque' => 'registrar_entrega_cliente',
            'divergencia' => 'reconciliar_divergencia',
            default => null,
        };
        $exigeRecebimento = $entregas->exigeRecebimentoAntesDaEntrega($this->resource);
        $liberadaExpedicao = $entregas->quantidadeLiberadaExpedicao($this->resource);
        $liberadaEntrega = $entregas->quantidadeLiberadaEntrega($this->resource);
        $bloqueadoPorRecebimento = $exigeRecebimento
            && $liberadaExpedicao <= 0
            && (int) $this->quantidade_expedida < $total;
        $quantidadeEmbarcada = $entregas->quantidadeEmbarcadaFabrica($this->resource);
        $quantidadeLiberadaRecebimento = max(
            0,
            min($total, $quantidadeEmbarcada ?? $total) - $recebida
        );
        $bloqueadoPorEmbarque = $quantidadeEmbarcada !== null
            && $recebida < $total
            && $quantidadeLiberadaRecebimento <= 0;

        return [
            'id' => $this->id,
            'tipo_origem' => $this->tipo_origem,
            'fluxo' => match ($this->tipo_origem) {
                \App\Models\ProdutoEntregaItem::ORIGEM_DEVOLUCAO => 'devolucao',
                \App\Models\ProdutoEntregaItem::ORIGEM_CONSIGNACAO => 'consignacao',
                \App\Models\ProdutoEntregaItem::ORIGEM_ASSISTENCIA => 'assistencia',
                default => 'pedido',
            },
            'etapa_operacional' => $etapaOperacional,
            'proxima_acao' => $proximaAcao,
            'origem_id' => $this->origem_id,
            'pedido_id' => $this->pedido_id,
            'pedido_item_id' => $this->pedido_item_id,
            'pedido_fabrica_item_id' => $this->pedido_fabrica_item_id,
            'consignacao_id' => $this->consignacao_id,
            'assistencia_item_id' => $this->assistencia_item_id,
            'devolucao_item_id' => $this->devolucao_item_id,
            'id_variacao' => $this->id_variacao,
            'quantidade_total' => $this->quantidade_total,
            'quantidade_reservada' => $this->quantidade_reservada,
            'quantidade_recebida' => $this->quantidade_recebida,
            'quantidade_expedida' => $this->quantidade_expedida,
            'quantidade_entregue' => $this->quantidade_entregue,
            'quantidade_pendente_recebimento' => max(0, (int) $this->quantidade_total - (int) $this->quantidade_recebida),
            'quantidade_embarcada_fabrica' => $quantidadeEmbarcada,
            'quantidade_liberada_recebimento' => $quantidadeLiberadaRecebimento,
            'bloqueado_por_embarque' => $bloqueadoPorEmbarque,
            'mensagem_bloqueio_embarque' => $bloqueadoPorEmbarque
                ? 'Aguardando embarque da fabrica para liberar o recebimento deste item.'
                : null,
            'quantidade_pendente_reserva' => max(0, $total - $atendido),
            'quantidade_pendente_expedicao' => max(0, (int) $this->quantidade_total - (int) $this->quantidade_expedida),
            'quantidade_pendente_entrega' => max(0, (int) $this->quantidade_expedida - (int) $this->quantidade_entregue),
            'quantidade_liberada_expedicao' => $liberadaExpedicao,
            'quantidade_liberada_entrega' => $liberadaEntrega,
            'bloqueado_por_recebimento' => $bloqueadoPorRecebimento,
            'mensagem_bloqueio_recebimento' => $bloqueadoPorRecebimento
                ? 'Registre o recebimento da fabrica antes de entregar ao cliente.'
                : null,
            'id_deposito_origem' => $this->id_deposito_origem,
            'id_deposito_destino' => $this->id_deposito_destino,
            'status' => $this->status,
            'status_label' => \App\Models\ProdutoEntregaItem::labelStatus($this->status),
            'em_revisao' => (bool) $this->em_revisao,
            'parcial' => $parcial,
            'previsao_entrega' => optional($this->previsao_entrega)?->toDateString(),
            'bloqueio_motivo' => $this->bloqueio_motivo,
            'pedido' => $this->whenLoaded('pedido'),
            'pedido_item' => $this->whenLoaded('pedidoItem'),
            'variacao' => $this->whenLoaded('variacao'),
            'deposito_origem' => $this->whenLoaded('depositoOrigem'),
            'deposito_destino' => $this->whenLoaded('depositoDestino'),
            'antecipacao' => $this->when(
                $this->relationLoaded('pedido') && $this->relationLoaded('eventos'),
                fn () => app(\App\Services\EntregaProdutoService::class)
                    ->estadoAntecipacaoItem($this->resource)
            ),
            'eventos' => $this->whenLoaded('eventos'),
            'created_at' => optional($this->created_at)?->toDateTimeString(),
            'updated_at' => optional($this->updated_at)?->toDateTimeString(),
        ];
    }
}
