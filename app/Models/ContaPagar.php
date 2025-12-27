<?php

namespace App\Models;

use App\Enums\ContaStatus;
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
        'valor_bruto','desconto','juros','multa','status',
        'categoria_id','centro_custo_id','observacoes'
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_vencimento' => 'date',
        'valor_bruto' => 'decimal:2',
        'desconto' => 'decimal:2',
        'juros' => 'decimal:2',
        'multa' => 'decimal:2',
        'status' => ContaStatus::class,
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanceira::class, 'categoria_id')->withDefault();
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id')->withDefault();
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'fornecedor_id')->withDefault();
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(ContaPagarPagamento::class, 'conta_pagar_id');
    }

    public function getValorLiquidoAttribute(): string
    {
        return (string) max(0, (float)($this->valor_bruto - $this->desconto + $this->juros + $this->multa));
    }

    public function getValorPagoAttribute(): string
    {
        return (string) $this->pagamentos()->sum('valor');
    }

    public function getSaldoAbertoAttribute(): string
    {
        return (string) max(0, (float)$this->valor_liquido - (float)$this->valor_pago);
    }
}
