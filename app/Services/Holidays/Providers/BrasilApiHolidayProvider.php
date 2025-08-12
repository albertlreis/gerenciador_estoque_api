<?php
namespace App\Services\Holidays\Providers;

use App\Services\Holidays\HolidayProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class BrasilApiHolidayProvider implements HolidayProviderInterface
{
    public function fetch(int $year, ?string $uf = null): Collection
    {
        $resp = Http::timeout(12)->retry(2, 500)->get("https://brasilapi.com.br/api/feriados/v1/{$year}");
        $resp->throw();

        return collect($resp->json())->map(fn($h) => [
            'date'   => $h['date'],   // YYYY-MM-DD
            'name'   => $h['name'],
            'escopo' => 'nacional',
            'uf'     => null,
            'fonte'  => 'brasilapi',
            'ano'    => $year,
        ]);
    }
}
