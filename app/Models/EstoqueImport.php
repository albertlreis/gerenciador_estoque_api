<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstoqueImport extends Model
{
    protected $table = 'estoque_imports';

    protected $fillable = [
        'arquivo_nome','arquivo_hash','usuario_id','status',
        'linhas_total','linhas_processadas','linhas_validas','linhas_invalidas',
        'metricas','mensagem'
    ];

    protected $casts = [
        'metricas' => 'array',
    ];

    /** @return HasMany<EstoqueImportRow> */
    public function rows(): HasMany
    {
        return $this->hasMany(EstoqueImportRow::class, 'import_id');
    }
}
