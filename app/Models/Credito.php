<?php
// app/Models/Credito.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Representa um crédito em loja gerado por devolução.
 *
 * @property int $devolucao_id
 * @property int $cliente_id
 * @property float $valor
 * @property bool $utilizado
 * @property \Illuminate\Support\Carbon|null $data_validade
 */
class Credito extends Model
{
    protected $fillable = ['devolucao_id', 'cliente_id', 'valor', 'utilizado', 'data_validade'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Devolucao, self>
     */
    public function devolucao(): BelongsTo
    {
        return $this->belongsTo(Devolucao::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Cliente, self>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
