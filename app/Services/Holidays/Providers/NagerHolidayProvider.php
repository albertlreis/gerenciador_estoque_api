<?php
namespace App\Services\Holidays\Providers;

use App\Services\Holidays\HolidayProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class NagerHolidayProvider implements HolidayProviderInterface
{
    public function fetch(int $year, ?string $uf = null): Collection
    {
        $resp = Http::timeout(12)->retry(2, 500)->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/BR");
        $resp->throw();

        return collect($resp->json())->map(fn($h) => [
            'date'   => $h['date'],
            'name'   => $h['localName'] ?? $h['name'],
            'escopo' => 'nacional',
            'uf'     => null,
            'fonte'  => 'nager',
            'ano'    => $year,
        ]);
    }
}
