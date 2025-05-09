<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Carrinho
 *
 * Representa o carrinho temporário do vendedor (usuário logado).
 */
class Carrinho extends Model
{
    protected $table = 'carrinhos';

    protected $fillable = [
        'id_usuario',
        'id_cliente',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CarrinhoItem::class, 'id_carrinho');
    }
}
