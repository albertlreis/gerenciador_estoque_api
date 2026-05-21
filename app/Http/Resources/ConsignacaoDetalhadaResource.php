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
        ];
    }
}
