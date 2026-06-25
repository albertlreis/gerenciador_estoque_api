<?php

namespace App\Integrations\Bancos\BancoDoBrasil;

use App\Integrations\Bancos\Exceptions\BancoDoBrasilIntegrationException;
use App\Models\ConciliacaoBancariaImportacao;
use App\Models\ContaFinanceira;
use App\Models\IntegracaoBancariaConexao;
use App\Services\ConciliacaoBancaria\ConciliacaoBancariaService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BancoDoBrasilExtratosService
{
    public const PROVIDER = 'bb_extratos';

    public function __construct(
        private readonly BancoDoBrasilExtratosClient $client,
        private readonly BancoDoBrasilStatementNormalizer $normalizer,
        private readonly ConciliacaoBancariaService $conciliacao
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function status(?int $contaFinanceiraId = null): array
    {
        $query = IntegracaoBancariaConexao::query()
            ->where('provedor', self::PROVIDER)
            ->with('contaFinanceira')
            ->orderByDesc('id');

        if ($contaFinanceiraId !== null) {
            $query->where('conta_financeira_id', $contaFinanceiraId);
        }

        $conexao = $query->first();

        return [
            'enabled' => $this->client->isEnabled(),
            'configured' => $this->client->isConfigured(),
            'connected' => $conexao?->status === 'ativa',
            'conexao' => $conexao ? $this->conexaoPayload($conexao) : null,
        ];
    }

    public function testarConexao(ContaFinanceira|int $contaFinanceira): IntegracaoBancariaConexao
    {
        $conta = $this->resolveConta($contaFinanceira);
        $this->assertBancoDoBrasil($conta);
        $conexao = $this->findOrCreateConexao($conta);

        try {
            $this->client->testConnection($conta);
            $meta = $conexao->meta_json ?: [];
            $meta['ultimo_teste_em'] = now()->toDateTimeString();

            $conexao->forceFill([
                'status' => 'ativa',
                'ultimo_erro' => null,
                'meta_json' => $meta,
            ])->save();

            return $conexao->fresh('contaFinanceira');
        } catch (BancoDoBrasilIntegrationException $e) {
            $this->markError($conexao, $e);

            throw $e;
        }
    }

    public function sincronizar(
        ContaFinanceira|int $contaFinanceira,
        CarbonInterface $start,
        CarbonInterface $end
    ): ConciliacaoBancariaImportacao {
        $conta = $this->resolveConta($contaFinanceira);
        $this->assertBancoDoBrasil($conta);
        $conexao = $this->findOrCreateConexao($conta);

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'data_fim' => 'A data final deve ser maior ou igual a data inicial.',
            ]);
        }

        $conexao->forceFill([
            'status' => 'sincronizando',
            'ultimo_erro' => null,
        ])->save();

        try {
            $raw = $this->client->fetchStatement($conta, $start, $end);
            $normalized = $this->normalizer->normalize($raw, $conta, $start, $end);
            $reference = sprintf('%s:%s:%s', self::PROVIDER, $start->toDateString(), $end->toDateString());

            $importacao = $this->conciliacao->importarExtratoNormalizado(
                $conta,
                $normalized,
                'bb_api',
                $reference,
                json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
            );

            $conexao->forceFill([
                'status' => 'ativa',
                'ultima_sincronizacao_em' => now(),
                'ultimo_periodo_inicio' => $start->toDateString(),
                'ultimo_periodo_fim' => $end->toDateString(),
                'ultimo_erro' => null,
            ])->save();

            return $importacao;
        } catch (BancoDoBrasilIntegrationException $e) {
            $this->markError($conexao, $e);

            throw $e;
        } catch (\Throwable $e) {
            $wrapped = new BancoDoBrasilIntegrationException(
                'Falha ao importar extrato BB: ' . $this->sanitizeError($e->getMessage()),
                'bb_extratos_import_error',
                [],
                $e
            );
            $this->markError($conexao, $wrapped);

            throw $wrapped;
        }
    }

    /**
     * @return array{success:int,failed:int,results:array<int,array<string,mixed>>}
     */
    public function sincronizarTodas(int $days = 7, ?int $contaFinanceiraId = null): array
    {
        $days = max(1, min($days, 90));
        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);

        $query = ContaFinanceira::query()
            ->ativas()
            ->whereIn('banco_codigo', ['001', '1'])
            ->orderBy('id');

        if ($contaFinanceiraId !== null) {
            $query->where('id', $contaFinanceiraId);
        }

        $results = [];
        $success = 0;
        $failed = 0;

        foreach ($query->get() as $conta) {
            try {
                $importacao = $this->sincronizar($conta, $start, $end);
                $success++;
                $results[] = [
                    'conta_financeira_id' => (int) $conta->id,
                    'status' => 'ok',
                    'importacao_id' => (int) $importacao->id,
                ];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'conta_financeira_id' => (int) $conta->id,
                    'status' => 'erro',
                    'message' => $this->sanitizeError($e->getMessage()),
                ];
            }
        }

        return compact('success', 'failed', 'results');
    }

    /**
     * @return array<string,mixed>
     */
    public function conexaoPayload(IntegracaoBancariaConexao $conexao): array
    {
        return [
            'id' => (int) $conexao->id,
            'conta_financeira_id' => (int) $conexao->conta_financeira_id,
            'provedor' => $conexao->provedor,
            'ambiente' => $conexao->ambiente,
            'status' => $conexao->status,
            'ultima_sincronizacao_em' => $conexao->ultima_sincronizacao_em?->toDateTimeString(),
            'ultimo_periodo_inicio' => $conexao->ultimo_periodo_inicio?->format('Y-m-d'),
            'ultimo_periodo_fim' => $conexao->ultimo_periodo_fim?->format('Y-m-d'),
            'ultimo_erro' => $conexao->ultimo_erro,
            'meta' => [
                'ultimo_teste_em' => $conexao->meta_json['ultimo_teste_em'] ?? null,
            ],
            'conta_financeira' => $conexao->relationLoaded('contaFinanceira') ? [
                'id' => $conexao->contaFinanceira?->id,
                'nome' => $conexao->contaFinanceira?->nome,
            ] : null,
        ];
    }

    private function findOrCreateConexao(ContaFinanceira $conta): IntegracaoBancariaConexao
    {
        return IntegracaoBancariaConexao::query()->firstOrCreate(
            [
                'conta_financeira_id' => $conta->id,
                'provedor' => self::PROVIDER,
            ],
            [
                'ambiente' => (string) config('banco_do_brasil.extratos.env', 'producao'),
                'status' => 'inativa',
            ]
        );
    }

    private function resolveConta(ContaFinanceira|int $contaFinanceira): ContaFinanceira
    {
        return $contaFinanceira instanceof ContaFinanceira
            ? $contaFinanceira
            : ContaFinanceira::query()->findOrFail($contaFinanceira);
    }

    private function assertBancoDoBrasil(ContaFinanceira $conta): void
    {
        $codigo = preg_replace('/\D+/', '', (string) $conta->banco_codigo) ?? '';
        if (ltrim($codigo, '0') !== '1') {
            throw ValidationException::withMessages([
                'conta_financeira_id' => 'A sincronizacao BB exige uma conta financeira do Banco do Brasil.',
            ]);
        }
    }

    private function markError(IntegracaoBancariaConexao $conexao, BancoDoBrasilIntegrationException $e): void
    {
        $conexao->forceFill([
            'status' => 'erro',
            'ultimo_erro' => $this->sanitizeError($e->getMessage()),
        ])->save();
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/(client_secret|client_id|app_key|access_token|authorization|bearer)\s*[=:]\s*[^,\s]+/i', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('/[A-Za-z0-9_\-]{28,}/', '[redacted]', $message) ?? $message;

        return Str::limit(trim($message), 500, '');
    }
}
