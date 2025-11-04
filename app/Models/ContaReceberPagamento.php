<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContaReceberPagamento extends Model
{
    protected $fillable = [
        'conta_receber_id', 'data_pagamento', 'valor_pago', 'forma_pagamento', 'comprovante'
    ];

    protected $casts = [
        'data_pagamento' => 'date',
    ];

    public function conta() {
        return $this->belongsTo(ContaReceber::class, 'conta_receber_id');
    }
}
