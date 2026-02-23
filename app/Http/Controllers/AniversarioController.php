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
        $filtros = $request->validate([
            'tipo' => ['nullable', 'in:clientes,parceiros,todos'],
            'dias' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $tipo = $filtros['tipo'] ?? 'todos';
        $dias = (int) ($filtros['dias'] ?? 7);

        $hoje = now()->startOfDay();
        $limite = $hoje->copy()->addDays($dias);

        $resultados = [];

        if (in_array($tipo, ['clientes', 'todos'], true)) {
            $clientes = Cliente::query()
                ->select(['id', 'nome', 'data_nascimento'])
                ->whereNotNull('data_nascimento')
                ->where('tipo', '!=', 'pj')
                ->get();

            foreach ($clientes as $cliente) {
                $proximo = $this->calcularProximoAniversario($cliente->data_nascimento, $hoje);
                if ($proximo->betweenIncluded($hoje, $limite)) {
                    $resultados[] = [
                        'tipo' => 'cliente',
                        'id' => $cliente->id,
                        'nome' => $cliente->nome,
                        'data_nascimento' => $cliente->data_nascimento?->toDateString(),
                        'dia_mes' => $proximo->format('d/m'),
                        'proximo_aniversario' => $proximo->toDateString(),
                    ];
                }
            }
        }

        if (in_array($tipo, ['parceiros', 'todos'], true)) {
            $parceiros = Parceiro::query()
                ->select(['id', 'nome', 'data_nascimento'])
                ->whereNotNull('data_nascimento')
                ->get();

            foreach ($parceiros as $parceiro) {
                $proximo = $this->calcularProximoAniversario($parceiro->data_nascimento, $hoje);
                if ($proximo->betweenIncluded($hoje, $limite)) {
                    $resultados[] = [
                        'tipo' => 'parceiro',
                        'id' => $parceiro->id,
                        'nome' => $parceiro->nome,
                        'data_nascimento' => $parceiro->data_nascimento?->toDateString(),
                        'dia_mes' => $proximo->format('d/m'),
                        'proximo_aniversario' => $proximo->toDateString(),
                    ];
                }
            }
        }

        usort($resultados, function (array $a, array $b) {
            if ($a['proximo_aniversario'] === $b['proximo_aniversario']) {
                return strcmp((string) $a['nome'], (string) $b['nome']);
            }

            return strcmp($a['proximo_aniversario'], $b['proximo_aniversario']);
        });

        return response()->json($resultados);
    }

    private function calcularProximoAniversario(?Carbon $dataNascimento, Carbon $referencia): Carbon
    {
        $anoAtual = (int) $referencia->year;
        $aniversarioAnoAtual = $this->normalizarDataAniversarioPorAno($dataNascimento, $anoAtual);

        if ($aniversarioAnoAtual->lt($referencia->copy()->startOfDay())) {
            return $this->normalizarDataAniversarioPorAno($dataNascimento, $anoAtual + 1);
        }

        return $aniversarioAnoAtual;
    }

    private function normalizarDataAniversarioPorAno(?Carbon $dataNascimento, int $ano): Carbon
    {
        $mes = (int) $dataNascimento?->month;
        $dia = (int) $dataNascimento?->day;

        // Em anos não bissextos, nascimento em 29/02 passa a considerar 28/02.
        if ($mes === 2 && $dia === 29 && !Carbon::create($ano, 1, 1)->isLeapYear()) {
            $dia = 28;
        }

        return Carbon::create($ano, $mes, $dia, 0, 0, 0, config('app.timezone'));
    }
}

