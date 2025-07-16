<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    public function estoque(): BelongsTo
    {
        return $this->belongsTo(Estoque::class, 'estoque_id');
    }
}
