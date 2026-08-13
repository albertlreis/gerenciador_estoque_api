<?php

namespace App\Http\Controllers\Integrations;

use App\Helpers\AuthHelper;
use App\Http\Controllers\Controller;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Models\Loja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ContaAzulLojaController extends Controller
{
    private const DEPENDENCY_TABLES = [
        'conta_azul_conexoes' => 'conexoes',
        'conta_azul_mapeamentos' => 'mapeamentos',
        'conta_azul_import_batches' => 'lotes_importacao',
        'conta_azul_sync_logs' => 'logs_sincronizacao',
        'conta_azul_reconciliation_states' => 'estados_reconciliacao',
        'conta_azul_cobrancas' => 'cobrancas',
        'notas_fiscais' => 'notas_fiscais',
        'stg_conta_azul_pessoas' => 'staging_pessoas',
        'stg_conta_azul_produtos' => 'staging_produtos',
        'stg_conta_azul_vendas' => 'staging_vendas',
        'stg_conta_azul_financeiro' => 'staging_financeiro',
        'stg_conta_azul_contas_pagar' => 'staging_contas_pagar',
        'stg_conta_azul_parcelas' => 'staging_parcelas',
        'stg_conta_azul_baixas' => 'staging_baixas',
        'stg_conta_azul_contas_financeiras' => 'staging_contas_financeiras',
        'stg_conta_azul_saldos_contas_financeiras' => 'staging_saldos',
        'stg_conta_azul_categorias_financeiras' => 'staging_categorias',
        'stg_conta_azul_centros_custo' => 'staging_centros_custo',
        'stg_conta_azul_formas_pagamento' => 'staging_formas_pagamento',
        'stg_conta_azul_notas' => 'staging_notas',
    ];

    public function index(): JsonResponse
    {
        if (! AuthHelper::podeAutenticarContaAzul()) {
            return $this->forbidden();
        }

        $lojas = Loja::query()
            ->orderBy('nome')
            ->get()
            ->map(fn (Loja $loja): array => $this->formatLoja($loja));

        return response()->json([
            'data' => $lojas,
            'legacy' => [
                'conexoes_sem_loja' => ContaAzulConexao::query()->whereNull('loja_id')->count(),
                'isolado' => true,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! AuthHelper::podeConfigurarContaAzul()) {
            return $this->forbidden();
        }

        $data = $this->validated($request);
        $data['codigo'] = $this->generateCodigo();
        $loja = Loja::create($data);

        return response()->json(['data' => $this->formatLoja($loja)], 201);
    }

    public function update(Request $request, Loja $loja): JsonResponse
    {
        if (! AuthHelper::podeConfigurarContaAzul()) {
            return $this->forbidden();
        }

        $loja->update($this->validated($request));

        return response()->json(['data' => $this->formatLoja($loja->fresh())]);
    }

    public function destroy(Loja $loja): JsonResponse
    {
        if (! AuthHelper::podeConfigurarContaAzul()) {
            return $this->forbidden();
        }

        $dependencies = $this->dependencyCounts($loja);
        if ($dependencies !== []) {
            return response()->json([
                'ok' => false,
                'reason' => 'registro_em_uso',
                'mensagem' => 'A loja possui registros da integração. Inative-a para preservar o histórico.',
                'dependencias' => $dependencies,
            ], 409);
        }

        $loja->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{nome:string,ativo:bool}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'nome' => ['required', 'string', 'max:190'],
            'ativo' => ['sometimes', 'boolean'],
        ]);
    }

    private function generateCodigo(): string
    {
        do {
            $codigo = str_replace('-', '', (string) Str::uuid());
        } while (Loja::query()->where('codigo', $codigo)->exists());

        return $codigo;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatLoja(Loja $loja): array
    {
        $conexao = ContaAzulConexao::query()
            ->where('loja_id', $loja->id)
            ->orderByDesc('id')
            ->first();

        return [
            'id' => $loja->id,
            'codigo' => $loja->codigo,
            'nome' => $loja->nome,
            'ativo' => (bool) $loja->ativo,
            'created_at' => optional($loja->created_at)->toISOString(),
            'updated_at' => optional($loja->updated_at)->toISOString(),
            'conexao' => $conexao?->only([
                'id',
                'loja_id',
                'status',
                'ambiente',
                'nome_externo',
                'ultimo_healthcheck_em',
                'ultimo_erro',
                'created_at',
                'updated_at',
            ]),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function dependencyCounts(Loja $loja): array
    {
        $counts = [];

        foreach (self::DEPENDENCY_TABLES as $table => $label) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'loja_id')) {
                continue;
            }

            $count = DB::table($table)->where('loja_id', $loja->id)->count();
            if ($count > 0) {
                $counts[$label] = $count;
            }
        }

        return $counts;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'Sem permissao para configurar a integracao Conta Azul.',
        ], 403);
    }
}
