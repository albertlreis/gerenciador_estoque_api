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
        'pedido_item_id',
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

    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function devolucoes(): HasMany
    {
        return $this->hasMany(ConsignacaoDevolucao::class);
    }

    public function compras(): HasMany
    {
        return $this->hasMany(ConsignacaoCompra::class);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(EstoqueMovimentacao::class, 'ref_id')
            ->where('ref_type', 'consignacao');
    }

    public function entregaItem()
    {
        return $this->hasOne(ProdutoEntregaItem::class, 'consignacao_id')
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_CONSIGNACAO);
    }

    public function quantidadeEnviada(): int
    {
        $entrega = $this->relationLoaded('entregaItem')
            ? $this->entregaItem
            : $this->entregaItem()->first();

        return max(0, (int) ($entrega?->quantidade_expedida ?? 0));
    }

    public function quantidadePendenteEnvio(): int
    {
        return max(0, (int) $this->quantidade - $this->quantidadeEnviada());
    }

    public function quantidadeDisponivelCliente(): int
    {
        return max(0, $this->quantidadeEnviada() - $this->quantidadeDevolvida() - $this->quantidadeComprada());
    }

    public function quantidadeDevolvida(): int
    {
        if (!$this->relationLoaded('devolucoes')) {
            return (int) $this->devolucoes()
                ->whereNull('consignacao_devolucoes.cancelada_em')
                ->sum('quantidade');
        }

        return (int) $this->devolucoes
            ->whereNull('cancelada_em')
            ->sum('quantidade');
    }

    public function quantidadeRestante(): int
    {
        return max(0, $this->quantidade - $this->quantidadeDevolvida() - $this->quantidadeComprada());
    }

    public function quantidadeComprada(): int
    {
        if (!$this->relationLoaded('compras')) {
            $comprada = (int) $this->compras()
                ->whereNull('consignacao_compras.cancelada_em')
                ->sum('quantidade');

            if ($comprada === 0 && $this->status === 'comprado') {
                return max(0, (int) $this->quantidade - $this->quantidadeDevolvida());
            }

            return $comprada;
        }

        $comprada = (int) $this->compras
            ->whereNull('cancelada_em')
            ->sum('quantidade');

        if ($comprada === 0 && $this->status === 'comprado') {
            return max(0, (int) $this->quantidade - $this->quantidadeDevolvida());
        }

        return $comprada;
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_id');
    }

}
