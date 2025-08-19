<?php

namespace App\Models;

use App\Enums\AssistenciaStatus;
use App\Enums\PrioridadeChamado;
use Illuminate\Database\Eloquent\Model;

/**
 * Chamado de assistência.
 */
class AssistenciaChamado extends Model
{
    protected $table = 'assistencia_chamados';

    protected $fillable = [
        'numero','origem_tipo','origem_id','cliente_id','fornecedor_id',
        'assistencia_id','status','prioridade','sla_data_limite',
        'canal_abertura','observacoes','created_by','updated_by'
    ];

    protected $casts = [
        'sla_data_limite' => 'date',
        'status' => AssistenciaStatus::class,
        'prioridade' => PrioridadeChamado::class,
    ];

    /** --- RELAÇÕES --- */

    public function cliente()     { return $this->belongsTo(Cliente::class, 'cliente_id'); }
    public function fornecedor()  { return $this->belongsTo(Fornecedor::class, 'fornecedor_id'); }
    public function assistencia() { return $this->belongsTo(Assistencia::class, 'assistencia_id'); }

    public function itens()
    {
        return $this->hasMany(AssistenciaChamadoItem::class, 'chamado_id');
    }

    public function logs()
    {
        return $this->hasMany(AssistenciaChamadoLog::class, 'chamado_id')->latest();
    }

    public function arquivos()
    {
        return $this->hasMany(AssistenciaArquivo::class, 'chamado_id');
    }
}
