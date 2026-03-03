<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParceiroContato extends Model
{
    use SoftDeletes;

    protected $table = 'parceiro_contatos';

    protected $fillable = [
        'parceiro_id',
        'tipo',
        'valor',
        'valor_e164',
        'rotulo',
        'principal',
        'observacoes',
    ];

    protected $casts = [
        'principal' => 'bool',
    ];

    public function parceiro(): BelongsTo
    {
        return $this->belongsTo(Parceiro::class, 'parceiro_id');
    }
}
