<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalizacaoEstoque extends Model
{
    use HasFactory;

    protected $table = 'localizacoes_estoque';

    protected $fillable = [
        'estoque_id',
        'corredor',
        'prateleira',
        'coluna',
        'nivel',
        'observacoes',
    ];

    /**
     * Estoque relacionado
     */
    public function estoque()
    {
        return $this->belongsTo(Estoque::class);
    }
}
