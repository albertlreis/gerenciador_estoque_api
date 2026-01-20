<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstoqueTransferencia extends Model
{
    protected $table = 'estoque_transferencias';

    protected $fillable = [
        'uuid',
        'deposito_origem_id',
        'deposito_destino_id',
        'id_usuario',
        'observacao',
        'status',
        'total_itens',
        'total_pecas',
        'concluida_em',
    ];

    protected $casts = [
        'concluida_em' => 'datetime',
    ];

    public function depositoOrigem(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_origem_id');
    }

    public function depositoDestino(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_destino_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(EstoqueTransferenciaItem::class, 'transferencia_id');
    }
}
