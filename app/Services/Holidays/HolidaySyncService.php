<?php
namespace App\Services\Holidays;

use App\Models\Feriado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class HolidaySyncService
{
    public function __construct(
        private readonly HolidayProviderInterface $nacionalPrimary,
        private readonly HolidayProviderInterface $nacionalFallback,
        private readonly HolidayProviderInterface $estadualProvider,
    ) {}

    public function syncNacionais(int $year): int
    {
        $collected = collect();
        foreach ([$this->nacionalPrimary, $this->nacionalFallback] as $prov) {
            try {
                $collected = $prov->fetch($year);
                if ($collected->isNotEmpty()) break;
            } catch (Throwable $e) {
                // log: provider falhou, tentar o próximo
            }
        }
        return $this->upsert($collected);
    }

    public function syncEstaduais(int $year, string $uf): int
    {
        $items = collect();
        try {
            $items = $this->estadualProvider->fetch($year, $uf);
        } catch (Throwable $e) {
            // log
        }
        return $this->upsert($items);
    }

    /** @param Collection<array{date:string,name:string,escopo:string,uf $items :?string,fonte:string,ano:int}> $items */
    private function upsert(Collection $items): int
    {
        if ($items->isEmpty()) return 0;

        DB::transaction(function () use ($items) {
            foreach ($items as $h) {
                Feriado::updateOrCreate(
                    ['data' => $h['date'], 'escopo' => $h['escopo'], 'uf' => $h['uf']],
                    ['nome' => $h['name'], 'fonte' => $h['fonte'], 'ano' => (int)$h['ano']]
                );
            }
        });

        return $items->count();
    }
}
