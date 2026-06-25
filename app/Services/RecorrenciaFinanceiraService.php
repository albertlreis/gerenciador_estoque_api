<?php

namespace App\Services;

use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\DespesaRecorrente;
use App\Models\DespesaRecorrenteExecucao;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RecorrenciaFinanceiraService
{
    public const MAX_OCORRENCIAS = 366;

    /**
     * @return array<int, Carbon>
     */
    public function datas(array $recorrencia, string $dataInicio): array
    {
        $frequencia = (string) ($recorrencia['frequencia'] ?? 'MENSAL');
        $intervalo = max(1, (int) ($recorrencia['intervalo'] ?? 1));
        $terminoTipo = (string) ($recorrencia['termino_tipo'] ?? 'OCORRENCIAS');
        $inicio = Carbon::parse($dataInicio)->startOfDay();
        $fim = !empty($recorrencia['data_fim']) ? Carbon::parse($recorrencia['data_fim'])->startOfDay() : null;

        if ($terminoTipo === 'DATA' && !$fim) {
            throw ValidationException::withMessages([
                'recorrencia.data_fim' => 'Informe a data final da recorrência.',
            ]);
        }

        if ($fim && $fim->lt($inicio)) {
            throw ValidationException::withMessages([
                'recorrencia.data_fim' => 'Data final não pode ser anterior ao primeiro vencimento.',
            ]);
        }

        $ocorrencias = $terminoTipo === 'DATA'
            ? self::MAX_OCORRENCIAS
            : min(self::MAX_OCORRENCIAS, max(1, (int) ($recorrencia['ocorrencias'] ?? 12)));

        $datas = [];
        $atual = $inicio->copy();

        while (count($datas) < $ocorrencias) {
            if ($fim && $atual->gt($fim)) {
                break;
            }

            $datas[] = $atual->copy();
            $atual = $this->proximaData($atual, $frequencia, $intervalo);
        }

        if (!$datas) {
            throw ValidationException::withMessages([
                'recorrencia' => 'A configuração não gerou nenhuma ocorrência.',
            ]);
        }

        return $datas;
    }

    public function criarSerie(array $base, array $recorrencia, string $direcao, ?int $usuarioId = null): DespesaRecorrente
    {
        $datas = $this->datas($recorrencia, (string) $base['data_vencimento']);
        $primeira = $datas[0];
        $ultima = $datas[count($datas) - 1];
        $frequencia = (string) ($recorrencia['frequencia'] ?? 'MENSAL');

        return DespesaRecorrente::create([
            'direcao' => $direcao,
            'fornecedor_id' => $direcao === 'PAGAR' ? ($base['fornecedor_id'] ?? null) : null,
            'cliente_id' => $direcao === 'RECEBER' ? ($base['cliente_id'] ?? null) : null,
            'descricao' => $base['descricao'],
            'numero_documento' => $base['numero_documento'] ?? null,
            'categoria_id' => $base['categoria_id'] ?? null,
            'centro_custo_id' => $base['centro_custo_id'] ?? null,
            'valor_bruto' => $base['valor_bruto'] ?? 0,
            'desconto' => $base['desconto'] ?? 0,
            'juros' => $base['juros'] ?? 0,
            'multa' => $base['multa'] ?? 0,
            'tipo' => 'FIXA',
            'frequencia' => $frequencia,
            'intervalo' => max(1, (int) ($recorrencia['intervalo'] ?? 1)),
            'dia_vencimento' => in_array($frequencia, ['MENSAL', 'ANUAL'], true) ? (int) $primeira->day : null,
            'mes_vencimento' => $frequencia === 'ANUAL' ? (int) $primeira->month : null,
            'data_inicio' => $primeira->toDateString(),
            'data_fim' => !empty($recorrencia['data_fim'])
                ? Carbon::parse($recorrencia['data_fim'])->toDateString()
                : $ultima->toDateString(),
            'criar_conta_pagar_auto' => true,
            'ocorrencias_total' => count($datas),
            'dias_antecedencia' => 0,
            'status' => 'ATIVA',
            'observacoes' => $base['observacoes'] ?? null,
            'usuario_id' => $usuarioId,
        ]);
    }

    public function registrarExecucao(DespesaRecorrente $serie, Model $conta, Carbon|string $competencia): void
    {
        $competenciaDate = $competencia instanceof Carbon
            ? $competencia->toDateString()
            : Carbon::parse($competencia)->toDateString();

        DespesaRecorrenteExecucao::create([
            'despesa_recorrente_id' => $serie->id,
            'competencia' => $competenciaDate,
            'data_prevista' => $competenciaDate,
            'data_geracao' => now(),
            'conta_pagar_id' => $conta instanceof ContaPagar ? $conta->id : null,
            'conta_receber_id' => $conta instanceof ContaReceber ? $conta->id : null,
            'status' => 'GERADA',
            'meta_json' => [
                'manual' => false,
                'direcao' => $serie->direcao,
            ],
        ]);
    }

    private function proximaData(Carbon $data, string $frequencia, int $intervalo): Carbon
    {
        return match ($frequencia) {
            'DIARIA' => $data->copy()->addDays($intervalo),
            'SEMANAL' => $data->copy()->addWeeks($intervalo),
            'ANUAL' => $data->copy()->addMonthsNoOverflow(12 * $intervalo),
            default => $data->copy()->addMonthsNoOverflow($intervalo),
        };
    }
}
