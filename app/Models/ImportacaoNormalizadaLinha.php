<?php

namespace App\Models;

use App\Enums\ImportacaoNormalizadaAcaoLinha;
use App\Enums\ImportacaoNormalizadaLinhaStatus;
use App\Enums\StatusRevisaoCadastro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportacaoNormalizadaLinha extends Model
{
    protected $table = 'importacoes_normalizadas_linhas';

    protected $fillable = [
        'importacao_id',
        'produto_id_vinculado',
        'variacao_id_vinculada',
        'aba_origem',
        'linha_planilha',
        'hash_linha',
        'dados_brutos',
        'dados_normalizados',
        'codigo',
        'codigo_origem',
        'codigo_modelo',
        'nome',
        'nome_normalizado',
        'nome_base_normalizado',
        'categoria',
        'categoria_normalizada',
        'categoria_oficial',
        'codigo_produto',
        'chave_produto',
        'chave_produto_calculada',
        'chave_variacao',
        'chave_variacao_calculada',
        'sku_interno',
        'conflito_codigo',
        'regra_categoria',
        'dimensao_1',
        'dimensao_2',
        'dimensao_3',
        'cor',
        'lado',
        'material_oficial',
        'acabamento_oficial',
        'quantidade',
        'status',
        'status_normalizado',
        'gera_estoque',
        'motivo_sem_estoque',
        'localizacao',
        'data_entrada',
        'valor',
        'custo',
        'outlet',
        'fornecedor',
        'avisos',
        'erros',
        'divergencias',
        'status_revisao',
        'status_processamento',
        'classificacao_acao',
        'produto_acao',
        'variacao_acao',
        'estoque_acao',
        'gera_movimentacao',
        'motivo_bloqueio',
        'resultado_preview',
        'resultado_execucao',
        'efetivada_em',
        'movimentacao_id',
        'erro_execucao',
        'decisao_manual',
        'motivo_decisao_manual',
    ];

    protected $casts = [
        'dados_brutos' => 'array',
        'dados_normalizados' => 'array',
        'conflito_codigo' => 'boolean',
        'dimensao_1' => 'decimal:2',
        'dimensao_2' => 'decimal:2',
        'dimensao_3' => 'decimal:2',
        'quantidade' => 'integer',
        'gera_estoque' => 'boolean',
        'data_entrada' => 'date',
        'valor' => 'decimal:2',
        'custo' => 'decimal:2',
        'outlet' => 'boolean',
        'avisos' => 'array',
        'erros' => 'array',
        'divergencias' => 'array',
        'status_revisao' => StatusRevisaoCadastro::class,
        'status_processamento' => ImportacaoNormalizadaLinhaStatus::class,
        'classificacao_acao' => ImportacaoNormalizadaAcaoLinha::class,
        'gera_movimentacao' => 'boolean',
        'resultado_preview' => 'array',
        'resultado_execucao' => 'array',
        'efetivada_em' => 'datetime',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(ImportacaoNormalizada::class, 'importacao_id');
    }

    public function produtoVinculado(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id_vinculado');
    }

    public function variacaoVinculada(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'variacao_id_vinculada');
    }

    public function conflitos(): HasMany
    {
        return $this->hasMany(ImportacaoNormalizadaConflito::class, 'linha_id');
    }

    public function revisoes(): HasMany
    {
        return $this->hasMany(ImportacaoNormalizadaRevisao::class, 'linha_id');
    }

    public function movimentacao(): BelongsTo
    {
        return $this->belongsTo(EstoqueMovimentacao::class, 'movimentacao_id');
    }
}
