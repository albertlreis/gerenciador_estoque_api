<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Representa uma devolução ou troca de itens de um pedido.
 *
 * @property int $pedido_id
 * @property string $tipo
 * @property string $motivo
 * @property string $status
 */
class Devolucao extends Model
{
    protected $table = 'devolucoes';
    protected $fillable = ['pedido_id', 'tipo', 'motivo', 'status'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Pedido, self>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\DevolucaoItem>
     */
    public function itens(): HasMany
    {
        return $this->hasMany(DevolucaoItem::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\App\Models\Credito>
     */
    public function credito(): HasOne
    {
        return $this->hasOne(Credito::class);
    }
}
