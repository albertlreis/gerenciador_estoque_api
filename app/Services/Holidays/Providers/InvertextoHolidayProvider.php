<?php
namespace App\Services\Holidays\Providers;

use App\Services\Holidays\HolidayProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InvertextoHolidayProvider implements HolidayProviderInterface
{
    public function fetch(int $year, ?string $uf = null): Collection
    {
        $token = env('INVERTEXTO_TOKEN');
        if (!$token) {
            throw new RuntimeException('INVERTEXTO_TOKEN ausente.');
        }

        $base = rtrim('https://api.invertexto.com/v1/holidays', '/');
        $url  = "$base/$year"; // <- ano no path

        $params = [
            'token'   => $token,
            // opcional, mas mantém claro o escopo Brasil:
            'country' => 'br',
        ];
        if ($uf) {
            $params['state'] = strtoupper($uf);
        }

        $resp = Http::timeout(15)
            ->retry(2, 500)
            ->acceptJson()
            ->get($url, $params)
            ->throw();

        $json = $resp->json();

        // Normalmente retorna ARRAY direto. Se mudar, garantimos array:
        $items = is_array($json) ? $json : (array)($json['data'] ?? []);

        return collect($items)
            ->filter(fn($h) => isset($h['date'], $h['name']))
            ->map(function ($h) use ($year, $uf) {
                $type   = strtolower((string)($h['type'] ?? ''));
                $escopo = str_contains($type, 'state')
                    ? 'estadual'
                    : (str_contains($type, 'national') ? 'nacional' : 'outro');

                return [
                    'date'   => (string)$h['date'],      // YYYY-MM-DD
                    'name'   => (string)$h['name'],
                    'escopo' => $escopo,
                    'uf'     => $escopo === 'estadual' ? strtoupper((string)$uf) : null,
                    'fonte'  => 'invertexto',
                    'ano'    => $year,
                ];
            })
            // Se pedimos UF, mantemos apenas os estaduais dessa UF
            ->when($uf, fn($c) => $c->where('escopo', 'estadual'))
            // Descarta “outros” (ex.: municipais/optionais) neste fluxo estadual
            ->reject(fn($h) => $h['escopo'] === 'outro')
            ->values();
    }
}
