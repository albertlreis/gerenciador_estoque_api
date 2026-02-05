<?php

namespace App\Services;

use App\Models\ContaPagar;
use App\Models\DespesaRecorrente;
use App\Repositories\DespesaRecorrenteExecucaoRepository;
use App\Repositories\DespesaRecorrenteRepository;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DespesaRecorrenteService
{
    public function __construct(
        protected DespesaRecorrenteRepository $repo,
        protected DespesaRecorrenteExecucaoRepository $execRepo,
    ) {}

    public function listar(array $filtros): LengthAwarePaginator
    {
        $perPage = (int)($filtros['per_page'] ?? 15);
        return $this->repo->paginate($filtros, max(1, min(200, $perPage)));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function criar(array $data, int $usuarioId): DespesaRecorrente
    {
        $data['usuario_id'] = $usuarioId;

        $this->validarRegrasDeRecorrencia($data);

        return $this->repo->create($data);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function atualizar(int $id, array $data): DespesaRecorrente
    {
        $model = $this->repo->findOrFail($id);

        // regra simples: não mudar chave de recorrência se já teve execuções
        if ($model->execucoes()->exists()) {
            foreach (['frequencia','intervalo','dia_vencimento','mes_vencimento','data_inicio'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] != $model->$field) {
                    throw ValidationException::withMessages([
                        $field => 'Não é permitido alterar este campo após existirem execuções.'
                    ]);
                }
            }
        }

        $this->validarRegrasDeRecorrencia(array_merge($model->toArray(), $data));

        return $this->repo->update($model, $data);
    }

    public function pausar(int $id): DespesaRecorrente
    {
        $model = $this->repo->findOrFail($id);
        return $this->repo->update($model, ['status' => 'PAUSADA']);
    }

    public function ativar(int $id): DespesaRecorrente
    {
        $model = $this->repo->findOrFail($id);
        return $this->repo->update($model, ['status' => 'ATIVA']);
    }

    public function cancelar(int $id): DespesaRecorrente
    {
        $model = $this->repo->findOrFail($id);
        return $this->repo->update($model, ['status' => 'CANCELADA']);
    }

    /**
     * Execução MANUAL: gera uma conta a pagar e grava execução.
     * $payload pode conter:
     * - competencia (YYYY-MM-01 ou YYYY-MM-DD)
     * - data_vencimento (YYYY-MM-DD) (opcional)
     * - valor_bruto (obrigatório se tipo=VARIAVEL ou se valor_bruto estiver null)
     */
    public function executarManual(int $id, array $payload): array
    {
        $despesa = $this->repo->findOrFail($id);

        if ($despesa->status !== 'ATIVA') {
            throw ValidationException::withMessages([
                'status' => 'Despesa recorrente não está ATIVA.'
            ]);
        }

        $competencia = $this->resolveCompetencia($payload, $despesa);
        $dataVenc = $this->resolveDataVencimento($payload, $despesa, $competencia);

        $valorBruto = $payload['valor_bruto'] ?? $despesa->valor_bruto;
        if ($despesa->tipo === 'VARIAVEL' && ($valorBruto === null || $valorBruto === '')) {
            throw ValidationException::withMessages([
                'valor_bruto' => 'Obrigatório para despesas do tipo VARIAVEL.'
            ]);
        }

        return DB::transaction(function () use ($despesa, $competencia, $dataVenc, $valorBruto, $payload) {
            // trava a linha (evita duas execuções simultâneas)
            $despesa = DespesaRecorrente::query()->lockForUpdate()->findOrFail($despesa->id);

            if ($this->execRepo->existsByCompetencia($despesa->id, $competencia->toDateString())) {
                throw ValidationException::withMessages([
                    'competencia' => 'Já existe execução para esta competência.'
                ]);
            }

            $conta = ContaPagar::create([
                'fornecedor_id'    => $despesa->fornecedor_id,
                'descricao'        => $despesa->descricao,
                'numero_documento' => $despesa->numero_documento,
                'data_emissao'     => Carbon::now()->toDateString(),
                'data_vencimento'  => $dataVenc->toDateString(),
                'valor_bruto'      => (float) $valorBruto,
                'desconto'         => (float) ($payload['desconto'] ?? $despesa->desconto ?? 0),
                'juros'            => (float) ($payload['juros'] ?? $despesa->juros ?? 0),
                'multa'            => (float) ($payload['multa'] ?? $despesa->multa ?? 0),
                'status'           => 'ABERTA',
                'centro_custo_id'  => $payload['centro_custo_id'] ?? $despesa->centro_custo_id,
                'categoria_id'     => $payload['categoria_id'] ?? $despesa->categoria_id,
                'observacoes'      => $payload['observacoes'] ?? $despesa->observacoes,
            ]);

            $exec = $this->execRepo->create([
                'despesa_recorrente_id' => $despesa->id,
                'competencia' => $competencia->toDateString(),
                'data_prevista' => $dataVenc->toDateString(),
                'data_geracao' => Carbon::now(),
                'conta_pagar_id' => $conta->id,
                'status' => 'GERADA',
                'meta_json' => [
                    'manual' => true,
                    'valor_bruto' => (float) $valorBruto,
                ],
            ]);

            return [
                'despesa_recorrente' => $despesa->fresh(),
                'conta_pagar' => $conta,
                'execucao' => $exec,
            ];
        });
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validarRegrasDeRecorrencia(array $data): void
    {
        $freq = $data['frequencia'] ?? 'MENSAL';

        if (in_array($freq, ['MENSAL','ANUAL'], true)) {
            if (empty($data['dia_vencimento'])) {
                throw ValidationException::withMessages([
                    'dia_vencimento' => 'Obrigatório para frequência MENSAL/ANUAL.'
                ]);
            }
        }

        if ($freq === 'ANUAL' && empty($data['mes_vencimento'])) {
            throw ValidationException::withMessages([
                'mes_vencimento' => 'Obrigatório para frequência ANUAL.'
            ]);
        }

        if (!empty($data['data_fim']) && !empty($data['data_inicio'])) {
            if (Carbon::parse($data['data_fim'])->lt(Carbon::parse($data['data_inicio']))) {
                throw ValidationException::withMessages([
                    'data_fim' => 'Data fim não pode ser menor que data início.'
                ]);
            }
        }

        $tipo = $data['tipo'] ?? 'FIXA';
        if ($tipo === 'FIXA' && (empty($data['valor_bruto']) && $data['valor_bruto'] !== 0 && $data['valor_bruto'] !== '0')) {
            throw ValidationException::withMessages([
                'valor_bruto' => 'Obrigatório para despesas do tipo FIXA.'
            ]);
        }
    }

    private function resolveCompetencia(array $payload, DespesaRecorrente $despesa): Carbon
    {
        if (!empty($payload['competencia'])) {
            $c = Carbon::parse($payload['competencia'])->startOfMonth();
            return $c;
        }
        // default: mês atual
        return Carbon::now()->startOfMonth();
    }

    private function resolveDataVencimento(array $payload, DespesaRecorrente $despesa, Carbon $competencia): Carbon
    {
        if (!empty($payload['data_vencimento'])) {
            return Carbon::parse($payload['data_vencimento']);
        }

        $freq = $despesa->frequencia;
        $dia = (int)($despesa->dia_vencimento ?? 1);

        if ($freq === 'ANUAL') {
            $mes = (int)($despesa->mes_vencimento ?? 1);
            $base = Carbon::create($competencia->year, $mes, 1)->startOfMonth();
            $diaFinal = min($dia, $base->daysInMonth);
            return $base->setDay($diaFinal);
        }

        if ($freq === 'MENSAL') {
            $base = $competencia->copy()->startOfMonth();
            $diaFinal = min($dia, $base->daysInMonth);
            return $base->setDay($diaFinal);
        }

        // para semanal/diária, por padrão vence "hoje" (manual pode passar data_vencimento)
        return Carbon::now();
    }
}
