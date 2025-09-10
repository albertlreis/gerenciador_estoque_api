<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Arquivos anexados aos chamados/itens de assistência.
 */
class AssistenciaArquivo extends Model
{
    protected $table = 'assistencia_arquivos';

    protected $fillable = [
        'chamado_id', 'item_id', 'tipo', 'path', 'nome_original', 'tamanho', 'mime'
    ];

    public function chamado()
    {
        return $this->belongsTo(AssistenciaChamado::class, 'chamado_id');
    }

    public function item()
    {
        return $this->belongsTo(AssistenciaChamadoItem::class, 'item_id');
    }
}
