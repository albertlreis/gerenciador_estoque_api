<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consignacao extends Model
{
    protected $table = 'consignacoes';

    protected $fillable = [
        'pedido_id',
        'produto_variacao_id',
        'deposito_id',
        'quantidade',
        'data_envio',
        'prazo_resposta',
        'status',
    ];

    protected $casts = [
        'data_envio' => 'date',
        'prazo_resposta' => 'date',
    ];

    /**
     * Pedido relacionado à consignação.
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * Variação do produto consignado.
     */
    public function produtoVariacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'produto_variacao_id');
    }

    public function devolucoes(): HasMany
    {
        return $this->hasMany(ConsignacaoDevolucao::class);
    }

    public function quantidadeDevolvida(): int
    {
        return $this->devolucoes->sum('quantidade');
    }

    public function quantidadeRestante(): int
    {
        return $this->quantidade - $this->quantidadeDevolvida();
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_id');
    }

}
