<?php

namespace App\Services;

use App\Models\Feriado;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BusinessDayService
{
    /**
     * Soma $diasUteis a partir de $inicio (exclui o dia inicial),
     * pulando sábados, domingos e feriados (nacionais + UF informada).
     *
     * @param Carbon $inicio
     * @param int $diasUteis
     * @param string|null $uf ex.: 'PA'
     * @return Carbon
     */
    public function addBusinessDays(Carbon $inicio, int $diasUteis, ?string $uf = null): Carbon
    {
        // Carrega feriados do(s) ano(s) necessários
        $anos = [$inicio->year, $inicio->copy()->addYear()->year];
        $feriados = $this->carregarFeriados($anos, $uf);

        $data = $inicio->copy();
        $adicionados = 0;

        while ($adicionados < $diasUteis) {
            $data->addDay();
            if ($this->isBusinessDay($data, $feriados)) {
                $adicionados++;
            }
        }
        return $data;
    }

    /**
     * Verifica se a data é dia útil (seg–sex, não feriado).
     */
    private function isBusinessDay(Carbon $data, Collection $feriados): bool
    {
        $isWeekend = $data->isWeekend(); // sábado/domingo
        if ($isWeekend) return false;

        return !$feriados->has($data->toDateString());
    }

    /**
     * Obtém feriados nacionais e estaduais (quando $uf não nulo) para os anos informados.
     * Retorna um Set (Collection com keys) no formato ['YYYY-MM-DD' => true, ...]
     *
     * @param array<int> $anos
     * @param string|null $uf
     * @return Collection
     */
    private function carregarFeriados(array $anos, ?string $uf = null): Collection
    {
        $q = Feriado::query()->whereIn('ano', $anos)->whereIn('escopo', ['nacional','estadual']);
        if ($uf) {
            // nacionais (uf null) OU estaduais da UF
            $q->where(function ($qq) use ($uf) {
                $qq->where('escopo', 'nacional')
                    ->orWhere(function ($q2) use ($uf) {
                        $q2->where('escopo','estadual')->where('uf',$uf);
                    });
            });
        }

        return $q->get()->pluck('data')->map(fn($d) => (string) $d->format('Y-m-d'))->flip();
    }
}
