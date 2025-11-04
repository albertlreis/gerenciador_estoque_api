<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContaReceber extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'pedido_id', 'descricao', 'numero_documento', 'data_emissao', 'data_vencimento',
        'valor_bruto', 'desconto', 'juros', 'multa', 'valor_liquido', 'valor_recebido',
        'saldo_aberto', 'status', 'forma_recebimento', 'centro_custo', 'categoria', 'observacoes'
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_vencimento' => 'date',
    ];

    public function pedido() {
        return $this->belongsTo(Pedido::class);
    }

    public function pagamentos() {
        return $this->hasMany(ContaReceberPagamento::class);
    }
}
