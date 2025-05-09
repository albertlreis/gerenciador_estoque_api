<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atributo de uma variação de produto (ex: cor = vermelho).
 */
class ProdutoVariacaoAtributo extends Model
{
    protected $table = 'produto_variacao_atributos';

    protected $fillable = ['id_variacao', 'atributo', 'valor'];

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }
}
