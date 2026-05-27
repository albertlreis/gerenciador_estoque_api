<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoImportacao extends Model
{
    protected $table = 'pedido_importacoes';

    protected $fillable = [
        'arquivo_nome',
        'arquivo_hash',
        'numero_externo',
        'pedido_id',
        'usuario_id',
        'status',
        'erro',
        'dados_json',
    ];

    protected $casts = [
        'dados_json' => 'array',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoImportacaoItem::class, 'pedido_importacao_id');
    }
}
