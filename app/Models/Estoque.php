<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Estoque extends Model
{
    protected $table = 'estoque';

    protected $fillable = [
        'id_variacao',
        'id_deposito',
        'quantidade',
        'corredor',
        'prateleira',
        'nivel',
    ];

    protected $casts = [
        'quantidade' => 'integer'
    ];

    /**
     * Relacionamento com a variação de produto
     */
    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao')->withDefault();
    }

    /**
     * Relacionamento com o depósito
     */
    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito')->withDefault();
    }

    public function localizacao(): HasOne
    {
        return $this->hasOne(LocalizacaoEstoque::class, 'estoque_id');
    }

}
