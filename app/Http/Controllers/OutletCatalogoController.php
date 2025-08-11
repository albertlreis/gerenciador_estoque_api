<?php

namespace App\Http\Controllers;

use App\Models\OutletMotivo;
use App\Models\OutletFormaPagamento;

class OutletCatalogoController extends Controller{
    public function motivos(){
        return response()->json(
            OutletMotivo::query()->where('ativo',true)->orderBy('nome')->get(['id','slug','nome'])
        );
    }
    public function formas(){
        return response()->json(
            OutletFormaPagamento::query()->where('ativo',true)->orderBy('nome')
                ->get(['id','slug','nome','percentual_desconto_default','max_parcelas_default'])
        );
    }
}
