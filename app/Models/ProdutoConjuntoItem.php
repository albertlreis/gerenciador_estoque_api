<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdutoConjuntoItem extends Model
{
    protected $table = 'produto_conjunto_itens';

    protected $fillable = [
        'produto_conjunto_id',
        'produto_variacao_id',
        'label',
        'ordem',
    ];

    public function conjunto(): BelongsTo
    {
        return $this->belongsTo(ProdutoConjunto::class, 'produto_conjunto_id');
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'produto_variacao_id');
    }
}
