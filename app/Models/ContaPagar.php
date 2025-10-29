<?php

namespace App\Models;

use App\Enums\ContaPagarStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContaPagar extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contas_pagar';

    protected $fillable = [
        'fornecedor_id','descricao','numero_documento','data_emissao','data_vencimento',
        'valor_bruto','desconto','juros','multa','status','forma_pagamento',
        'centro_custo','categoria','observacoes'
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_vencimento' => 'date',
        'valor_bruto' => 'decimal:2',
        'desconto' => 'decimal:2',
        'juros' => 'decimal:2',
        'multa' => 'decimal:2',
        'status' => ContaPagarStatus::class,
    ];

    /** @return BelongsTo<Fornecedor,ContaPagar> */
    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'fornecedor_id');
    }

    /** @return HasMany<ContaPagarPagamento> */
    public function pagamentos(): HasMany
    {
        return $this->hasMany(ContaPagarPagamento::class, 'conta_pagar_id');
    }

    public function getValorPagoAttribute(): string
    {
        return (string) $this->pagamentos()->sum('valor');
    }

    public function getSaldoAbertoAttribute(): string
    {
        $liquido = $this->valor_bruto - $this->desconto + $this->juros + $this->multa;
        return (string) max(0, $liquido - (float) $this->valor_pago);
    }
}
