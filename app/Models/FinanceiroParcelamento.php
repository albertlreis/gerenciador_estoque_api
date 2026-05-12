<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceiroParcelamento extends Model
{
    protected $table = 'financeiro_parcelamentos';

    protected $fillable = [
        'tipo',
        'descricao',
        'numero_documento',
        'valor_total',
        'valor_entrada',
        'quantidade_parcelas',
        'intervalo_meses',
        'data_emissao',
        'primeiro_vencimento',
        'created_by',
    ];

    protected $casts = [
        'valor_total' => 'decimal:2',
        'valor_entrada' => 'decimal:2',
        'quantidade_parcelas' => 'integer',
        'intervalo_meses' => 'integer',
        'data_emissao' => 'date',
        'primeiro_vencimento' => 'date',
    ];

    public function contasPagar(): HasMany
    {
        return $this->hasMany(ContaPagar::class, 'parcelamento_id');
    }

    public function contasReceber(): HasMany
    {
        return $this->hasMany(ContaReceber::class, 'parcelamento_id');
    }
}
