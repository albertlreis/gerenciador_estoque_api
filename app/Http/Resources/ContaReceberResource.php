<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContaReceberResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'descricao'         => $this->descricao,
            'numero_documento'  => $this->numero_documento,
            'data_emissao'      => optional($this->data_emissao)->format('Y-m-d'),
            'data_vencimento'   => optional($this->data_vencimento)->format('Y-m-d'),
            'valor_bruto'       => (float) $this->valor_bruto,
            'desconto'          => (float) $this->desconto,
            'juros'             => (float) $this->juros,
            'multa'             => (float) $this->multa,
            'valor_liquido'     => (float) $this->valor_liquido,
            'valor_recebido'    => (float) $this->valor_recebido,
            'saldo_aberto'      => (float) $this->saldo_aberto,
            'status'            => $this->status,
            'forma_recebimento' => $this->forma_recebimento,
            'centro_custo'      => $this->centro_custo,
            'categoria'         => $this->categoria,
            'observacoes'       => $this->observacoes,

            // 🔗 Integração com pedido e cliente
            'pedido' => $this->whenLoaded('pedido', function () {
                return [
                    'id' => $this->pedido->id,
                    'numero' => $this->pedido->numero ?? null,
                    'data' => optional($this->pedido->data)->format('Y-m-d'),
                    'cliente' => $this->pedido->cliente->nome ?? null,
                ];
            }),

            // 💰 Pagamentos realizados
            'pagamentos' => $this->whenLoaded('pagamentos', function () {
                return $this->pagamentos->map(fn($p) => [
                    'id' => $p->id,
                    'data_pagamento' => optional($p->data_pagamento)->format('Y-m-d'),
                    'valor_pago' => (float) $p->valor_pago,
                    'forma_pagamento' => $p->forma_pagamento,
                    'comprovante' => $p->comprovante,
                ]);
            }),

            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
