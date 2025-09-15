<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstoqueImportRow extends Model
{
    protected $table = 'estoque_import_rows';

    protected $fillable = [
        'import_id','linha_planilha','hash_linha',
        'cod','nome','categoria','madeira','tecido_1','tecido_2','metal_vidro',
        'localizacao','deposito','cliente','data_nf','data','valor','qtd',
        'parsed_dimensoes','parsed_localizacao','valido','erros','warnings',
    ];

    protected $casts = [
        'parsed_dimensoes' => 'array',
        'parsed_localizacao' => 'array',
        'valido' => 'boolean',
        'erros' => 'array',
        'warnings' => 'array',
        'data_nf' => 'date',
        'data' => 'date',
        'valor' => 'decimal:2',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(EstoqueImport::class, 'import_id');
    }
}
