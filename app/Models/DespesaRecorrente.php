<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DespesaRecorrente extends Model
{
    use SoftDeletes;

    protected $table = 'despesas_recorrentes';

    protected $fillable = [
        'fornecedor_id',
        'descricao',
        'numero_documento',
        'centro_custo',
        'categoria',
        'valor_bruto',
        'desconto',
        'juros',
        'multa',
        'tipo',
        'frequencia',
        'intervalo',
        'dia_vencimento',
        'mes_vencimento',
        'data_inicio',
        'data_fim',
        'criar_conta_pagar_auto',
        'dias_antecedencia',
        'status',
        'observacoes',
        'usuario_id',
    ];

    protected $casts = [
        'valor_bruto' => 'decimal:2',
        'desconto' => 'decimal:2',
        'juros' => 'decimal:2',
        'multa' => 'decimal:2',
        'intervalo' => 'integer',
        'dia_vencimento' => 'integer',
        'mes_vencimento' => 'integer',
        'criar_conta_pagar_auto' => 'boolean',
        'dias_antecedencia' => 'integer',
        'data_inicio' => 'date',
        'data_fim' => 'date',
    ];

    // Relacionamentos

    public function execucoes(): HasMany
    {
        return $this->hasMany(DespesaRecorrenteExecucao::class, 'despesa_recorrente_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'fornecedor_id')->withDefault();
    }

     public function usuario(): BelongsTo
     {
         return $this->belongsTo(Usuario::class, 'usuario_id')->withDefault();
     }

    // Helpers “de domínio” (opcional, mas ajuda MUITO)
    public function isAtiva(): bool
    {
        return $this->status === 'ATIVA';
    }

    public function isVariavel(): bool
    {
        return $this->tipo === 'VARIAVEL';
    }
}
