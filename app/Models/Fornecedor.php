<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fornecedor extends Model
{
    use SoftDeletes;

    protected $table = "fornecedores";

    protected $fillable = [
        'nome', 'cnpj', 'email', 'telefone', 'endereco', 'status', 'observacoes'
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    // Normaliza CNPJ ao salvar (apenas dígitos)
    public function setCnpjAttribute(?string $value): void
    {
        $clean = $value ? preg_replace('/\D+/', '', $value) : null;
        $this->attributes['cnpj'] = $clean ?: null;
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class, 'id_fornecedor');
    }
}
