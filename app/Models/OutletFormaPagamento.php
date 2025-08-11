<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class OutletFormaPagamento extends Model{
    protected $table='outlet_formas_pagamento';
    protected $fillable=['slug','nome','max_parcelas_default','percentual_desconto_default','ativo'];
    protected $casts=['ativo'=>'boolean','percentual_desconto_default'=>'decimal:2','max_parcelas_default'=>'integer'];
}
