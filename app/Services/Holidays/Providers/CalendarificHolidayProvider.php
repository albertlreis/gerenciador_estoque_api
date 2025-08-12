<?php
namespace App\Services\Holidays\Providers;

use App\Services\Holidays\HolidayProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CalendarificHolidayProvider implements HolidayProviderInterface
{
    public function __construct(private ?string $apiKey = null) {}

    public function fetch(int $year, ?string $uf = null): Collection
    {
        $apiKey = $this->apiKey ?? config('services.calendarific.key') ?? env('CALENDARIFIC_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('CALENDARIFIC_API_KEY ausente.');
        }

        $baseUrl = 'https://calendarific.com/api/v2/holidays';
        $common  = [
            'api_key' => $apiKey,
            'country' => 'BR',
            'year'    => $year,
        ];

        // 1) Tenta direto por subdivisão ISO (BR-PA)
        $items = collect();
        if ($uf) {
            $params = $common + ['location' => 'BR-'.strtoupper($uf)];
            $resp = Http::timeout(15)->retry(2, 500)->acceptJson()->get($baseUrl, $params);
            if ($resp->successful()) {
                $items = collect(data_get($resp->json(), 'response.holidays', []));
            }
        }

        // 2) Se veio vazio, baixa BR inteiro e filtra localmente por UF
        if ($items->isEmpty()) {
            $respAll = Http::timeout(15)->retry(2, 500)->acceptJson()->get($baseUrl, $common);
            $respAll->throw();
            $items = collect(data_get($respAll->json(), 'response.holidays', []));
        }

        $UF = $uf ? strtoupper($uf) : null;

        return $items->map(function ($h) use ($year, $UF) {
            $date      = (string) data_get($h, 'date.iso');
            $name      = (string) data_get($h, 'name');

            // Formas possíveis no payload:
            $locations = (string) data_get($h, 'locations', 'All'); // string com "All" ou lista separada por vírgulas
            $statesRaw = data_get($h, 'states');                    // pode ser array, objeto ou null
            $subsRaw   = data_get($h, 'subdivisions');              // algumas respostas usam subdivisions

            $states = collect();
            if (is_array($statesRaw)) {
                $states = collect($statesRaw);
            } elseif (is_object($statesRaw)) {
                $states = collect([$statesRaw]); // objeto único
            }

            $subs = collect();
            if (is_array($subsRaw)) {
                $subs = collect($subsRaw);
            } elseif (is_object($subsRaw)) {
                $subs = collect([$subsRaw]);
            }

            // Detecta se é feriado limitado por estado (não nacional)
            $isStateScoped = $locations !== 'All' || $states->isNotEmpty() || $subs->isNotEmpty();

            // Checa se cobre a UF desejada (quando pedida)
            $coversUF = true;
            if ($UF && $isStateScoped) {
                $coversUF =
                    // states: podem vir campos 'abbrev' (PA) ou 'code' (BR-PA)
                    $states->contains(fn($s) =>
                        strtoupper((string) data_get($s, 'abbrev')) === $UF
                        || strtoupper((string) data_get($s, 'code')) === ('BR-'.$UF)
                        || str_contains(strtoupper((string) data_get($s, 'id','')), $UF)
                    )
                    // subdivisions: idem
                    || $subs->contains(fn($s) =>
                        strtoupper((string) data_get($s, 'abbrev')) === $UF
                        || strtoupper((string) data_get($s, 'code')) === ('BR-'.$UF)
                        || str_contains(strtoupper((string) data_get($s, 'id','')), $UF)
                    )
                    // fallback: 'locations' como string "BR-PA, BR-RJ" ou "PA, RJ"
                    || collect(preg_split('/\s*,\s*/', $locations))
                        ->filter()->contains(fn($loc) =>
                            strtoupper(trim((string)$loc)) === $UF
                            || strtoupper(trim((string)$loc)) === ('BR-'.$UF)
                        );
            }

            return [
                'date'   => $date,
                'name'   => $name,
                'escopo' => $isStateScoped ? 'estadual' : 'nacional',
                'uf'     => $isStateScoped ? ($UF ?: null) : null,
                'fonte'  => 'calendarific',
                'ano'    => $year,
                '_coversUF' => $coversUF,
            ];
        })
            // Guardamos apenas estaduais e, se UF foi informada, apenas os que cobrem essa UF
            ->filter(function ($x) use ($UF) {
                if ($x['escopo'] !== 'estadual') return false;
                return $UF ? ($x['_coversUF'] ?? false) : true;
            })
            ->map(fn($x) => collect($x)->except('_coversUF')->all());
    }
}
