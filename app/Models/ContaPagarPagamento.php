<?php


namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class ContaPagarPagamento extends Model
{
    use HasFactory;

    protected $table = 'contas_pagar_pagamentos';

    protected $fillable = [
        'conta_pagar_id','data_pagamento','valor','forma_pagamento','comprovante_path','observacoes','usuario_id'
    ];

    protected $casts = [
        'data_pagamento' => 'date',
        'valor' => 'decimal:2',
    ];

    /** @return BelongsTo<ContaPagar,ContaPagarPagamento> */
    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaPagar::class, 'conta_pagar_id');
    }

    /** @return BelongsTo<Usuario,ContaPagarPagamento> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
