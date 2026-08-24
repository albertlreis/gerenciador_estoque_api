<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model CarrinhoItem
 *
 * Representa um item dentro de um carrinho temporário.
 */
class CarrinhoItem extends Model
{
    protected $table = 'carrinho_itens';

    protected $fillable = [
        'id_carrinho',
        'id_variacao',
        'quantidade',
        'preco_unitario',
        'subtotal',
        'id_deposito',
        'outlet_id',
        'outlet_pagamento_id',
        'outlet_preco_base',
        'outlet_forma_pagamento_id',
        'outlet_forma_pagamento',
        'outlet_percentual_desconto',
        'outlet_max_parcelas',
        'outlet_preco_final',
    ];

    protected $casts = [
        'preco_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'outlet_preco_base' => 'decimal:2',
        'outlet_percentual_desconto' => 'decimal:2',
        'outlet_preco_final' => 'decimal:2',
    ];

    public function carrinho(): BelongsTo
    {
        return $this->belongsTo(Carrinho::class, 'id_carrinho');
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacaoOutlet::class, 'outlet_id');
    }

    public function outletPagamento(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacaoOutletPagamento::class, 'outlet_pagamento_id');
    }

    /**
     * Retorna o nome do produto relacionado, se carregado.
     */
    public function getNomeProdutoAttribute(): ?string
    {
        return $this->variacao?->produto?->nome;
    }

    /**
     * Retorna o nome completo da variação (produto + atributos), se disponível.
     */
    public function getNomeCompletoAttribute(): ?string
    {
        return $this->variacao?->nome_completo;
    }
}
