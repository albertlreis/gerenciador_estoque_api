<?php

namespace App\Models;

use App\Enums\AssistenciaStatus;
use App\Enums\CustoResponsavel;
use App\Enums\LocalReparo;
use App\Enums\PrioridadeChamado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistenciaChamado extends Model
{
    protected $table = 'assistencia_chamados';

    protected $fillable = [
        'numero',
        'origem_tipo',
        'origem_id',
        'pedido_id',
        'assistencia_id',
        'status',
        'prioridade',
        'sla_data_limite',
        'local_reparo',
        'custo_responsavel',
        'observacoes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sla_data_limite'   => 'date',
        'status'            => AssistenciaStatus::class,
        'prioridade'        => PrioridadeChamado::class,
        'local_reparo'      => LocalReparo::class,
        'custo_responsavel' => CustoResponsavel::class,
    ];

    /** --- RELAÇÕES --- */

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function assistencia(): BelongsTo
    {
        return $this->belongsTo(Assistencia::class, 'assistencia_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(AssistenciaChamadoItem::class, 'chamado_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AssistenciaChamadoLog::class, 'chamado_id')->latest();
    }

    public function arquivos(): HasMany
    {
        return $this->hasMany(AssistenciaArquivo::class, 'chamado_id');
    }
}
