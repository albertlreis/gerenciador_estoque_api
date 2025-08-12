<?php
namespace App\Services\Holidays;

use Illuminate\Support\Collection;

interface HolidayProviderInterface
{
    /**
     * @return Collection<array{date:string,name:string,escopo:string,uf:?string,fonte:string,ano:int}>
     */
    public function fetch(int $year, ?string $uf = null): Collection;
}
