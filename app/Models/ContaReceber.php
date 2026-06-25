<?php

namespace App\Models;

use App\Enums\ContaStatus;
use App\Integrations\ContaAzul\Models\ContaAzulCobranca;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContaReceber extends Model
{
    use SoftDeletes;

    protected $table = 'contas_receber';

    protected $fillable = [
        'parcelamento_id','parcela_numero','parcelas_total','is_entrada',
        'despesa_recorrente_id','recorrencia_competencia',
        'pedido_id','cliente_id','descricao','numero_documento','data_emissao','data_vencimento',
        'valor_bruto','desconto','juros','multa',
        'valor_liquido','valor_recebido','saldo_aberto',
        'status','forma_recebimento',
        'categoria_id','centro_custo_id','observacoes'
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_vencimento' => 'date',
        'recorrencia_competencia' => 'date',
        'valor_bruto' => 'decimal:2',
        'desconto' => 'decimal:2',
        'juros' => 'decimal:2',
        'multa' => 'decimal:2',
        'valor_liquido' => 'decimal:2',
        'valor_recebido' => 'decimal:2',
        'saldo_aberto' => 'decimal:2',
        'is_entrada' => 'boolean',
        'status' => ContaStatus::class,
    ];

    public function parcelamento(): BelongsTo
    {
        return $this->belongsTo(FinanceiroParcelamento::class, 'parcelamento_id')->withDefault();
    }

    public function recorrencia(): BelongsTo
    {
        return $this->belongsTo(DespesaRecorrente::class, 'despesa_recorrente_id')->withDefault();
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanceira::class, 'categoria_id')->withDefault();
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id')->withDefault();
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class)->withDefault();
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id')->withDefault();
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(ContaReceberPagamento::class, 'conta_receber_id');
    }

    public function cobrancaContaAzul(): HasOne
    {
        return $this->hasOne(ContaAzulCobranca::class, 'conta_receber_id');
    }

    public function getValorLiquidoAttribute(): string
    {
        return (string) max(0, (float)($this->valor_bruto - $this->desconto + $this->juros + $this->multa));
    }

    public function getValorRecebidoAttribute(): string
    {
        return (string) $this->pagamentos()->sum('valor');
    }

    public function getSaldoAbertoAttribute(): string
    {
        return (string) max(0, (float)$this->valor_liquido - (float)$this->valor_recebido);
    }
}
