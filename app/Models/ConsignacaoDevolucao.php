<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignacaoDevolucao extends Model
{
    protected $table = 'consignacao_devolucoes';

    protected $fillable = ['consignacao_id', 'usuario_id', 'quantidade', 'observacoes', 'data_devolucao'];

    protected $casts = [
        'data_devolucao' => 'datetime',
    ];

    public function consignacao(): BelongsTo
    {
        return $this->belongsTo(Consignacao::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
