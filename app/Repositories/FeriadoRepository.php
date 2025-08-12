<?php

namespace App\Repositories;

use App\Models\Feriado;
use Illuminate\Support\Collection;

class FeriadoRepository
{
    public function listarPara(string $uf = null, string $cidade = null, int $ano = null): Collection
    {
        $q = Feriado::query();
        if ($ano) $q->whereYear('data', $ano);

        // nacionais
        $q1 = (clone $q)->where('tipo','nacional');

        // estaduais
        $q2 = (clone $q)->where('tipo','estadual')->when($uf, fn($x)=>$x->where('uf',$uf));

        // municipais
        $q3 = (clone $q)->where('tipo','municipal')
            ->when($uf, fn($x)=>$x->where('uf',$uf))
            ->when($cidade, fn($x)=>$x->where('cidade',$cidade));

        return $q1->unionAll($q2)->unionAll($q3)->get()->pluck('data');
    }
}
