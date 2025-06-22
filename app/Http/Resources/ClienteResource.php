<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $nome
 * @property string|null $email
 * @property string|null $telefone
 * @property string|null $cpf_cnpj
 * @property string|null $cep
 * @property string|null $endereco
 * @property string|null $numero
 * @property string|null $bairro
 * @property string|null $cidade
 * @property string|null $uf
 * @property string|null $complemento
 */
class ClienteResource extends JsonResource
{
    /**
     * Transforma o recurso em um array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'nome'         => $this->nome,
            'email'        => $this->email,
            'telefone'     => $this->telefone,
            'cpf_cnpj'     => $this->cpf_cnpj,

            'cep'          => $this->cep,
            'endereco'     => $this->endereco,
            'numero'       => $this->numero,
            'bairro'       => $this->bairro,
            'cidade'       => $this->cidade,
            'uf'           => $this->uf,
            'complemento'  => $this->complemento,
        ];
    }
}
