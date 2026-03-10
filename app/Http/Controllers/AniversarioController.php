<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Parceiro;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AniversarioController extends Controller
{
    public function index(Request $request): JsonResponse
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
                ->where(function ($q): void {
                    $q->whereNull('tipo')->orWhere('tipo', '!=', 'pj');
                })
                ->get(['id', 'nome', 'data_nascimento']);

            $itens = $itens->merge($clientes->map(function (Cliente $cliente) use ($hoje) {
                $proximo = $this->calcularProximoAniversario($cliente->data_nascimento, $hoje);

                return [
                    'tipo' => 'cliente',
                    'id' => $cliente->id,
                    'nome' => $cliente->nome,
                    'data_nascimento' => Carbon::parse($cliente->data_nascimento)->format('Y-m-d'),
                    'dia_mes' => $proximo->format('d/m'),
                    'proximo_aniversario' => $proximo->format('Y-m-d'),
                ];
            }));
        }

        if (in_array($tipo, ['parceiros', 'todos'], true)) {
            $parceiros = Parceiro::query()
                ->whereNotNull('data_nascimento')
                ->get(['id', 'nome', 'data_nascimento']);

            $itens = $itens->merge($parceiros->map(function (Parceiro $parceiro) use ($hoje) {
                $proximo = $this->calcularProximoAniversario($parceiro->data_nascimento, $hoje);

                return [
                    'tipo' => 'parceiro',
                    'id' => $parceiro->id,
                    'nome' => $parceiro->nome,
                    'data_nascimento' => Carbon::parse($parceiro->data_nascimento)->format('Y-m-d'),
                    'dia_mes' => $proximo->format('d/m'),
                    'proximo_aniversario' => $proximo->format('Y-m-d'),
                ];
            }));
        }

        $limite = $hoje->copy()->addDays($dias);

        $resultado = $itens
            ->filter(function (array $item) use ($hoje, $limite): bool {
                $data = Carbon::parse($item['proximo_aniversario']);
                return $data->betweenIncluded($hoje, $limite);
            })
            ->sortBy([
                ['proximo_aniversario', 'asc'],
                ['nome', 'asc'],
            ])
            ->values();

        return response()->json($resultado);
    }

    private function calcularProximoAniversario(string $dataNascimento, Carbon $referencia): Carbon
    {
        $nascimento = Carbon::parse($dataNascimento);
        $mes = (int) $nascimento->format('m');
        $dia = (int) $nascimento->format('d');

        $ano = (int) $referencia->format('Y');
        $proximo = $this->montarDataAniversario($ano, $mes, $dia);

        if ($proximo->lt($referencia)) {
            $proximo = $this->montarDataAniversario($ano + 1, $mes, $dia);
        }

        return $proximo;
    }

    private function montarDataAniversario(int $ano, int $mes, int $dia): Carbon
    {
        if ($mes === 2 && $dia === 29 && !Carbon::create($ano)->isLeapYear()) {
            return Carbon::create($ano, 2, 28)->startOfDay();
        }

        return Carbon::create($ano, $mes, $dia)->startOfDay();
    }
}
