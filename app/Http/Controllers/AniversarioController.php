<?php

namespace App\Http\Controllers;

use App\Http\Requests\AniversarioIndexRequest;
use App\Models\Cliente;
use App\Models\Parceiro;
use App\Services\AniversarioService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class AniversarioController extends Controller
{
    public function __construct(private readonly AniversarioService $service)
    {
    }

    public function index(AniversarioIndexRequest $request): JsonResponse
    {
        if ($request->hasAny(['tipo', 'dias'])) {
            return $this->legacyIndex($request);
        }

        return response()->json($this->service->listar($request->validated()));
    }

    private function legacyIndex(AniversarioIndexRequest $request): JsonResponse
    {
        $tipo = strtolower((string) $request->query('tipo', 'todos'));
        if (!in_array($tipo, ['clientes', 'parceiros', 'todos'], true)) {
            $tipo = 'todos';
        }

        $dias = max(0, min(365, (int) $request->query('dias', 7)));
        $hoje = Carbon::today();
        $itens = collect();

        if (in_array($tipo, ['clientes', 'todos'], true)) {
            $clientes = Cliente::query()
                ->whereNotNull('data_nascimento')
                ->where(fn ($query) => $query->whereNull('tipo')->orWhere('tipo', '!=', 'pj'))
                ->get(['id', 'nome', 'data_nascimento']);

            $itens = $itens->merge($clientes->map(
                fn (Cliente $cliente) => $this->legacyItem('cliente', $cliente, $hoje)
            ));
        }

        if (in_array($tipo, ['parceiros', 'todos'], true)) {
            $parceiros = Parceiro::query()
                ->whereNotNull('data_nascimento')
                ->get(['id', 'nome', 'data_nascimento']);

            $itens = $itens->merge($parceiros->map(
                fn (Parceiro $parceiro) => $this->legacyItem('parceiro', $parceiro, $hoje)
            ));
        }

        $limite = $hoje->copy()->addDays($dias);

        return response()->json(
            $itens
                ->filter(fn (array $item) => Carbon::parse($item['proximo_aniversario'])
                    ->betweenIncluded($hoje, $limite))
                ->sortBy([
                    ['proximo_aniversario', 'asc'],
                    ['nome', 'asc'],
                ])
                ->values()
        );
    }

    private function legacyItem(string $tipo, Cliente|Parceiro $pessoa, Carbon $hoje): array
    {
        $nascimento = Carbon::parse($pessoa->data_nascimento);
        $proximo = $this->legacyBirthdayOccurrence($hoje->year, $nascimento->month, $nascimento->day);

        if ($proximo->lt($hoje)) {
            $proximo = $this->legacyBirthdayOccurrence($hoje->year + 1, $nascimento->month, $nascimento->day);
        }

        return [
            'tipo' => $tipo,
            'id' => $pessoa->id,
            'nome' => $pessoa->nome,
            'data_nascimento' => $nascimento->format('Y-m-d'),
            'dia_mes' => $proximo->format('d/m'),
            'proximo_aniversario' => $proximo->format('Y-m-d'),
        ];
    }

    private function legacyBirthdayOccurrence(int $ano, int $mes, int $dia): Carbon
    {
        if ($mes === 2 && $dia === 29 && !Carbon::create($ano)->isLeapYear()) {
            return Carbon::create($ano, 2, 28)->startOfDay();
        }

        return Carbon::create($ano, $mes, $dia)->startOfDay();
    }
}
