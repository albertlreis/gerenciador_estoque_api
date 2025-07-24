<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdutoVariacaoOutletPagamento extends Model
{
    protected $table = 'produto_variacao_outlet_pagamentos';

    protected $fillable = ['produto_variacao_outlet_id', 'forma_pagamento', 'percentual_desconto', 'max_parcelas'];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacaoOutlet::class, 'produto_variacao_outlet_id');
    }
}
