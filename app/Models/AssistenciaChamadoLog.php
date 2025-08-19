<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Histórico de mudanças de status e eventos do chamado/itens.
 */
class AssistenciaChamadoLog extends Model
{
    protected $table = 'assistencia_chamado_logs';

    protected $fillable = [
        'chamado_id','item_id','status_de','status_para',
        'mensagem','meta_json','user_id'
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function chamado() { return $this->belongsTo(AssistenciaChamado::class, 'chamado_id'); }
    public function item()    { return $this->belongsTo(AssistenciaChamadoItem::class, 'item_id'); }
    public function usuario() { return $this->belongsTo(Usuario::class, 'user_id'); }
}
