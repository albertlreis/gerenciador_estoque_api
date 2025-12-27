<?php

namespace App\Models;

use App\Enums\FormaPagamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContaReceberPagamento extends Model
{
    protected $table = 'contas_receber_pagamentos';

    protected $fillable = [
        'conta_receber_id','data_pagamento','valor','forma_pagamento',
        'comprovante_path','observacoes','usuario_id','conta_financeira_id'
    ];

    protected $casts = [
        'data_pagamento' => 'date',
        'valor' => 'decimal:2',
        'forma_pagamento' => FormaPagamento::class,
    ];

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaReceber::class, 'conta_receber_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id')->withDefault();
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id')->withDefault();
    }
}
