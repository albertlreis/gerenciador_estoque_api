<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deposito extends Model
{
    protected $fillable = [
        'nome',
        'endereco'
    ];

    /** @return HasMany<Estoque> */
    public function estoque(): HasMany
    {
        return $this->hasMany(Estoque::class, 'id_deposito');
    }

    /** @return HasMany<EstoqueMovimentacao> */
    public function movimentacoesOrigem(): HasMany
    {
        return $this->hasMany(EstoqueMovimentacao::class, 'id_deposito_origem');
    }

    /** @return HasMany<EstoqueMovimentacao> */
    public function movimentacoesDestino(): HasMany
    {
        return $this->hasMany(EstoqueMovimentacao::class, 'id_deposito_destino');
    }
}
