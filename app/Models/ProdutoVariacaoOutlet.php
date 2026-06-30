<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProdutoVariacaoOutlet extends Model
{
    protected $table = 'produto_variacao_outlets';

    protected $fillable = [
        'produto_variacao_id',
        'motivo_id',
        'quantidade',
        'quantidade_restante',
        'usuario_id',
        'produto_variacao_imagem_id',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'quantidade_restante' => 'integer',
        'produto_variacao_imagem_id' => 'integer',
    ];

    public const MOTIVOS = [
        'tempo_estoque' => 'Tempo em estoque',
        'saiu_linha' => 'Saiu de linha',
        'avariado' => 'Avariado',
        'devolvido' => 'Devolvido',
        'exposicao' => 'Exposição em loja',
        'embalagem_danificada' => 'Embalagem danificada',
        'baixa_rotatividade' => 'Baixa rotatividade',
        'erro_cadastro' => 'Erro de cadastro',
        'excedente' => 'Reposição excedente',
        'promocao_pontual' => 'Promoção pontual',
    ];


    /**
     * Variação do produto relacionada.
     */
    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'produto_variacao_id');
    }

    public function imagemSelecionada(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacaoImagem::class, 'produto_variacao_imagem_id');
    }

    /**
     * Usuário que registrou o item como outlet.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(AcessoUsuario::class, 'usuario_id');
    }

    /**
     * Verifica se o registro ainda possui saldo para outlet.
     */
    public function isAtivo(): bool
    {
        return $this->quantidade_restante > 0;
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(OutletMotivo::class, 'motivo_id');
    }
    public function formasPagamento(): HasMany
    {
        return $this->hasMany(ProdutoVariacaoOutletPagamento::class, 'produto_variacao_outlet_id');
    }

}
