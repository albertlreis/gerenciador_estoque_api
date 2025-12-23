<?php

namespace App\Models;

use App\Enums\ContaStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContaReceber extends Model
{
    use SoftDeletes;

    protected $table = 'contas_receber';

    protected $fillable = [
        'pedido_id','descricao','numero_documento','data_emissao','data_vencimento',
        'valor_bruto','desconto','juros','multa','status',
        'centro_custo','categoria','observacoes'
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

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class)->withDefault();
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(ContaReceberPagamento::class, 'conta_receber_id');
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
