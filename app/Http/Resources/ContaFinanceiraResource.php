<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContaFinanceiraResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int)$this->id,
            'nome' => (string)$this->nome,
            'slug' => (string)$this->slug,
            'tipo' => (string)$this->tipo,
            'moeda' => (string)$this->moeda,
            'ativo' => (bool)$this->ativo,
            'padrao' => (bool)$this->padrao,
            'saldo_inicial' => $this->saldo_inicial !== null ? (string)$this->saldo_inicial : null,

            'banco_nome' => $this->banco_nome,
            'banco_codigo' => $this->banco_codigo,
            'agencia' => $this->agencia,
            'agencia_dv' => $this->agencia_dv,
            'conta' => $this->conta,
            'conta_dv' => $this->conta_dv,
            'titular_nome' => $this->titular_nome,
            'titular_documento' => $this->titular_documento,
            'chave_pix' => $this->chave_pix,
            'observacoes' => $this->observacoes,
            'meta_json' => $this->meta_json,
        ];
    }
}
