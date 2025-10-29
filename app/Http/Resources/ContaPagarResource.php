<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ContaPagar */
class ContaPagarResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'descricao' => $this->descricao,
            'numero_documento' => $this->numero_documento,
            'data_emissao' => optional($this->data_emissao)->format('Y-m-d'),
            'data_vencimento' => optional($this->data_vencimento)->format('Y-m-d'),
            'valor_bruto' => (float) $this->valor_bruto,
            'desconto' => (float) $this->desconto,
            'juros' => (float) $this->juros,
            'multa' => (float) $this->multa,
            'valor_liquido' => (float) ($this->valor_bruto - $this->desconto + $this->juros + $this->multa),
            'valor_pago' => (float) $this->valor_pago,
            'saldo_aberto' => (float) $this->saldo_aberto,
            'status' => $this->status->value,
            'forma_pagamento' => $this->forma_pagamento,
            'centro_custo' => $this->centro_custo,
            'categoria' => $this->categoria,
            'observacoes' => $this->observacoes,
            'fornecedor' => $this->whenLoaded('fornecedor', fn() => [
                'id' => $this->fornecedor?->id,
                'nome' => $this->fornecedor?->nome,
            ]),
            'pagamentos' => ContaPagarPagamentoResource::collection($this->whenLoaded('pagamentos')),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
            'deleted_at' => optional($this->deleted_at)->toDateTimeString(),
        ];
    }
}
