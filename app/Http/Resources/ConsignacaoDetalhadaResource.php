<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConsignacaoDetalhadaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'pedido_id' => $this->pedido_id,

            'cliente_nome' => optional($this->pedido->cliente)->nome,

            'quantidade' => $this->quantidade,
            'quantidade_disponivel' => $this->quantidadeRestante(),
            'quantidade_comprada' => $this->quantidadeComprada(),
            'quantidade_devolvida' => $this->quantidadeDevolvida(),

            'data_envio' => optional($this->data_envio)->format('d/m/Y'),
            'prazo_resposta' => optional($this->prazo_resposta)->format('d/m/Y'),
            'data_resposta' => optional($this->data_resposta)->format('d/m/Y'),

            'status' => $this->status,
            'observacoes' => $this->observacoes,

            // Nome do produto (completo para exibição)
            'produto_nome' => optional($this->produtoVariacao)->nome_completo,

            // Bloco estruturado do produto e variação
            'produto' => [
                'id' => $this->produto_variacao_id,
                'nome' => optional($this->produtoVariacao->produto)->nome,
                'variacao' => $this->produtoVariacao->nome_completo,
                'descricao' => $this->produtoVariacao->descricao ?? null,
                'imagem' => optional($this->produtoVariacao->produto->imagemPrincipal)->url ?? null,
            ],

            // Histórico de devoluções
            'devolucoes' => $this->devolucoes->map(function ($devolucao) {
                $cancelada = (bool) $devolucao->cancelada_em;
                return [
                    'id' => $devolucao->id,
                    'quantidade' => $devolucao->quantidade,
                    'observacoes' => $devolucao->observacoes,
                    'data_devolucao' => optional($devolucao->data_devolucao)->format('d/m/Y H:i'),
                    'cancelada' => $cancelada,
                    'cancelada_em' => optional($devolucao->cancelada_em)->format('d/m/Y H:i'),
                    'motivo_cancelamento' => $devolucao->motivo_cancelamento,
                    'pode_cancelar' => !$cancelada,
                    'usuario' => [
                        'id' => $devolucao->usuario->id ?? null,
                        'nome' => $devolucao->usuario->nome ?? null,
                    ],
                    'cancelada_por' => [
                        'id' => $devolucao->canceladaPor->id ?? null,
                        'nome' => $devolucao->canceladaPor->nome ?? null,
                    ],
                ];
            }),
            'compras' => $this->compras->map(function ($compra) {
                $cancelada = (bool) $compra->cancelada_em;
                return [
                    'id' => $compra->id,
                    'quantidade' => $compra->quantidade,
                    'observacoes' => $compra->observacoes,
                    'data_compra' => optional($compra->data_compra)->format('d/m/Y H:i'),
                    'cancelada' => $cancelada,
                    'cancelada_em' => optional($compra->cancelada_em)->format('d/m/Y H:i'),
                    'motivo_cancelamento' => $compra->motivo_cancelamento,
                    'usuario' => [
                        'id' => $compra->usuario->id ?? null,
                        'nome' => $compra->usuario->nome ?? null,
                    ],
                    'cancelada_por' => [
                        'id' => $compra->canceladaPor->id ?? null,
                        'nome' => $compra->canceladaPor->nome ?? null,
                    ],
                ];
            }),
            'movimentacoes' => $this->whenLoaded('movimentacoes', function () {
                return $this->movimentacoes->map(fn ($movimentacao) => [
                    'id' => $movimentacao->id,
                    'tipo' => $movimentacao->tipo,
                    'quantidade' => $movimentacao->quantidade,
                    'data_movimentacao' => optional($movimentacao->data_movimentacao)->format('d/m/Y H:i'),
                    'deposito_origem_nome' => $movimentacao->depositoOrigem?->nome,
                    'deposito_destino_nome' => $movimentacao->depositoDestino?->nome,
                    'observacao' => $movimentacao->observacao,
                    'usuario_nome' => $movimentacao->usuario?->nome,
                ])->values();
            }),
        ];
    }
}
