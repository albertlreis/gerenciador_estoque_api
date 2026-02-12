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
        'data_entrada_estoque_atual',
        'ultima_venda_em',
        'corredor',
        'prateleira',
        'nivel',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'data_entrada_estoque_atual' => 'datetime',
        'ultima_venda_em' => 'datetime',
    ];

    /** @return BelongsTo<ProdutoVariacao,Estoque> */
    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao')->withDefault();
    }

    /** @return BelongsTo<Deposito,Estoque> */
    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito')->withDefault();
    }

    /** @return HasOne<LocalizacaoEstoque> */
    public function localizacao(): HasOne
    {
        return $this->hasOne(LocalizacaoEstoque::class, 'estoque_id');
    }

}
