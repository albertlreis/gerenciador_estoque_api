<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContaFinanceiraOptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'     => (int) $this->id,
            'nome'   => (string) $this->nome,
            'slug'   => (string) $this->slug,
            'tipo'   => (string) $this->tipo,
            'ativo'  => (bool) $this->ativo,
            'padrao' => (bool) $this->padrao,
            'moeda'  => (string) $this->moeda,
            'data_saldo_inicial' => $this->data_saldo_inicial?->format('Y-m-d'),
            'saldo_atual' => $this->saldo_atual !== null ? (string) $this->saldo_atual : null,
            'saldo_atual_em' => $this->saldo_atual_em !== null ? (string) $this->saldo_atual_em : null,
            'saldo_base_origem' => $this->saldo_base_origem,

            'label'  => (string) $this->nome,
            'value'  => (int) $this->id,
        ];
    }
}
