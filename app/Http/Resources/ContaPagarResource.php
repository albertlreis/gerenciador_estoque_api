<?php

namespace App\Http\Resources;

use BackedEnum;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ContaPagar */
class ContaPagarResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'fornecedor_id'    => $this->fornecedor_id ? (int) $this->fornecedor_id : null,
            'categoria_id'     => $this->categoria_id ? (int) $this->categoria_id : null,
            'centro_custo_id'  => $this->centro_custo_id ? (int) $this->centro_custo_id : null,
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
            'status' => $this->status instanceof BackedEnum
                ? $this->status->value
                : ($this->status ?? 'INDEFINIDO'),
            'categoria' => $this->whenLoaded('categoria', fn() => [
                'id' => $this->categoria?->id,
                'nome' => $this->categoria?->nome,
                'tipo' => $this->categoria?->tipo,
            ]),
            'centro_custo' => $this->whenLoaded('centroCusto', fn() => [
                'id' => $this->centroCusto?->id,
                'nome' => $this->centroCusto?->nome,
            ]),

            'observacoes' => $this->observacoes,
            'fornecedor' => $this->whenLoaded('fornecedor', fn() => [
                'id' => $this->fornecedor?->id,
                'nome' => $this->fornecedor?->nome,
            ]),
            'pagamentos' => ContaPagarPagamentoResource::collection($this->whenLoaded('pagamentos')),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
//            'deleted_at' => optional($this->deleted_at)->toDateTimeString(),
        ];
    }
}
