<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoStatusDefinicao extends Model
{
    protected $table = 'pedido_statuses';

    protected $fillable = [
        'codigo',
        'nome',
        'descricao',
        'cor',
        'severidade',
        'icone',
        'ativo',
        'sistema',
        'protegido',
        'papel_operacional',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'sistema' => 'boolean',
        'protegido' => 'boolean',
    ];

    public function fluxoItens(): HasMany
    {
        return $this->hasMany(PedidoStatusFluxoItem::class, 'pedido_status_id');
    }
}
