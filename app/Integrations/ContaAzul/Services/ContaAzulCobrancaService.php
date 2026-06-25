<?php

namespace App\Integrations\ContaAzul\Services;

use App\Enums\ContaStatus;
use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Mappers\ContaAzulCobrancaMapper;
use App\Integrations\ContaAzul\Models\ContaAzulCobranca;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\ContaReceber;
use App\Services\AuditoriaLogService;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContaAzulCobrancaService
{
    public function __construct(
        private readonly array $config,
        private readonly ContaAzulConnectionService $connections,
        private readonly ContaAzulClient $client,
        private readonly ExportacaoContaAzulService $exportacao,
        private readonly ContaAzulCobrancaMapper $mapper,
        private readonly AuditoriaLogService $auditoria
    ) {
    }

    public function gerarBoleto(ContaReceber $conta, ?int $lojaId = null): ContaAzulCobranca
    {
        $conta->loadMissing(['cliente', 'pedido.cliente', 'cobrancaContaAzul']);
        $this->validarConta($conta);

        $existente = $conta->cobrancaContaAzul;
        if ($existente && $existente->status === 'emitida') {
            throw ValidationException::withMessages([
                'conta_receber_id' => 'Boleto Conta Azul ja emitido para esta conta a receber.',
            ]);
        }

        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao || $conexao->status !== 'ativa') {
            throw new ContaAzulException(
                'Conexao Conta Azul inativa ou em erro. Reconecte a Conta Azul antes de emitir boletos.',
                'conexao_inativa'
            );
        }

        $idTituloExterno = $this->ensureTituloExterno($conexao, $conta, $lojaId);
        $payload = $this->mapper->boletoFromLocal($conta, $idTituloExterno, $this->config['cobranca'] ?? []);
        $cobranca = $existente ?: new ContaAzulCobranca(['conta_receber_id' => $conta->id]);

        $cobranca->fill([
            'loja_id' => $lojaId,
            'tipo' => (string) (($this->config['cobranca']['tipo_pagamento'] ?? 'BOLETO')),
            'status' => 'pendente',
            'payload_json' => $payload,
            'payload_resumo' => $this->jsonResumo($payload),
            'erro_codigo' => null,
            'erro_mensagem' => null,
            'ultima_tentativa_em' => now(),
        ])->save();

        $path = (string) ($this->config['paths']['cobranca_create'] ?? '/v1/financeiro/eventos-financeiros/contas-a-receber/gerar-cobranca');
        $token = $this->connections->getValidAccessToken($conexao);

        try {
            $res = $this->client->post(ltrim($path, '/'), $token, $payload);
        } catch (Throwable $e) {
            $message = 'Falha de transporte ao gerar boleto Conta Azul: ' . $e->getMessage();
            $this->marcarErro($cobranca, 'transporte', $message, null);
            throw new ContaAzulException($message, 'cobranca_transporte_falhou', [], $e);
        }

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $json = is_array($res['json']) ? $res['json'] : [];
        $resumo = isset($res['body']) ? mb_substr((string) $res['body'], 0, 2000) : null;

        if (!$ok) {
            $message = $this->formatErrorMessage((int) $res['status'], $json, $res['body'] ?? null);
            $this->marcarErro($cobranca, (string) $res['status'], $message, $resumo, $json);
            $this->registrarAuditoria($conta, $cobranca, $payload, $resumo, false, $message, (string) $res['status']);

            throw new ContaAzulException($message, $this->isLegacyUnsupportedStatus((int) $res['status']) ? 'cobranca_api_indisponivel' : 'cobranca_falhou');
        }

        $idExterno = $this->extractFirstString($json, [
            'id',
            'uuid',
            'id_cobranca',
            'cobranca.id',
            'charge.id',
            'data.id',
        ]);

        $cobranca->fill([
            'status' => 'emitida',
            'id_externo' => $idExterno,
            'url' => $this->extractFirstString($json, [
                'url',
                'link',
                'link_cobranca',
                'url_cobranca',
                'boleto_url',
                'cobranca.url',
                'cobranca.link',
                'data.url',
                'data.link',
                'pagamento.url',
                'pagamento.link',
            ]),
            'linha_digitavel' => $this->extractFirstString($json, [
                'linha_digitavel',
                'linhaDigitavel',
                'boleto.linha_digitavel',
                'boleto.linhaDigitavel',
                'cobranca.linha_digitavel',
                'data.linha_digitavel',
            ]),
            'codigo_barras' => $this->extractFirstString($json, [
                'codigo_barras',
                'codigoBarras',
                'boleto.codigo_barras',
                'boleto.codigoBarras',
                'data.codigo_barras',
            ]),
            'response_json' => $json,
            'resposta_resumo' => $resumo,
            'erro_codigo' => null,
            'erro_mensagem' => null,
            'emitida_em' => now(),
        ])->save();

        if ($idExterno) {
            ContaAzulMapeamento::updateOrCreate(
                [
                    'loja_id' => $lojaId,
                    'tipo_entidade' => ContaAzulEntityType::COBRANCA,
                    'id_local' => (int) $conta->id,
                ],
                [
                    'id_externo' => $idExterno,
                    'origem_inicial' => 'export',
                    'sincronizado_em' => now(),
                    'metadata_json' => ['response' => $json],
                ]
            );
        }

        $this->registrarAuditoria($conta, $cobranca, $payload, $resumo, true, null, null);

        return $cobranca->refresh();
    }

    private function validarConta(ContaReceber $conta): void
    {
        $status = $conta->status instanceof \BackedEnum ? $conta->status->value : (string) $conta->status;
        if (in_array($status, [ContaStatus::PAGA->value, ContaStatus::CANCELADA->value], true)) {
            throw ValidationException::withMessages([
                'status' => 'Nao e possivel emitir boleto para conta paga ou cancelada.',
            ]);
        }

        if ((float) $conta->saldo_aberto <= 0) {
            throw ValidationException::withMessages([
                'saldo_aberto' => 'Nao e possivel emitir boleto para conta sem saldo em aberto.',
            ]);
        }

        $clienteId = $conta->cliente_id ?: $conta->pedido?->id_cliente;
        if (!$clienteId) {
            throw ValidationException::withMessages([
                'cliente_id' => 'Informe um cliente antes de emitir boleto pela Conta Azul.',
            ]);
        }
    }

    private function ensureTituloExterno(ContaAzulConexao $conexao, ContaReceber $conta, ?int $lojaId): string
    {
        $idTituloExterno = ContaAzulMapeamento::idExternoPorLocal(ContaAzulEntityType::TITULO, (int) $conta->id, $lojaId);
        if ($idTituloExterno) {
            return $idTituloExterno;
        }

        $this->exportacao->exportarTitulo($conexao, $conta, $lojaId);

        $idTituloExterno = ContaAzulMapeamento::idExternoPorLocal(ContaAzulEntityType::TITULO, (int) $conta->id, $lojaId);
        if (!$idTituloExterno) {
            throw new ContaAzulException(
                'Nao foi possivel obter o id externo do titulo Conta Azul para gerar o boleto.',
                'titulo_sem_id_externo'
            );
        }

        return $idTituloExterno;
    }

    private function marcarErro(
        ContaAzulCobranca $cobranca,
        string $codigo,
        string $message,
        ?string $resumo,
        array $json = []
    ): void {
        $cobranca->fill([
            'status' => 'erro',
            'erro_codigo' => $codigo,
            'erro_mensagem' => $message,
            'resposta_resumo' => $resumo,
            'response_json' => $json ?: null,
        ])->save();
    }

    private function formatErrorMessage(int $status, array $json, ?string $body): string
    {
        if ($this->isLegacyUnsupportedStatus($status)) {
            return 'Sua integração Conta Azul não suporta geração de cobranças via API. Verifique se a aplicação foi criada depois de março de 2025 e se a conta possui cobranças habilitadas.';
        }

        foreach (['mensagem', 'message', 'descricao_erro', 'error_description', 'error'] as $key) {
            $value = $json[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return 'Falha ao gerar boleto Conta Azul: ' . (string) $value;
            }
        }

        $bodyResumo = $body ? trim(preg_replace('/\s+/', ' ', $body) ?? '') : '';
        if ($bodyResumo !== '') {
            return 'Falha ao gerar boleto Conta Azul: HTTP ' . $status . ' - ' . mb_substr($bodyResumo, 0, 240);
        }

        return 'Falha ao gerar boleto Conta Azul: HTTP ' . $status;
    }

    private function isLegacyUnsupportedStatus(int $status): bool
    {
        return in_array($status, [403, 404], true);
    }

    /**
     * @param array<string, mixed> $json
     * @param array<int, string> $paths
     */
    private function extractFirstString(array $json, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->arrayGet($json, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayGet(array $data, string $path): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResumo(array $payload): string
    {
        return mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 0, 2000);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function registrarAuditoria(
        ContaReceber $conta,
        ContaAzulCobranca $cobranca,
        array $payload,
        ?string $respostaResumo,
        bool $ok,
        ?string $erroMensagem,
        ?string $erroCodigo
    ): void {
        $this->auditoria->registrar([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => $ok ? 'info' : 'error',
            'modulo' => 'conta_azul',
            'acao' => 'gerar_boleto',
            'status' => $ok ? 'sucesso' : 'falha',
            'label' => 'Emissao de boleto Conta Azul',
            'message' => $erroMensagem ?: $respostaResumo,
            'entity_type' => ContaAzulEntityType::COBRANCA,
            'entity_id' => $conta->id,
            'context_json' => [
                'conta_receber_id' => $conta->id,
                'cobranca_id' => $cobranca->id,
                'id_externo' => $cobranca->id_externo,
                'payload_resumo' => $this->jsonResumo($payload),
                'resposta_resumo' => $respostaResumo,
                'erro_codigo' => $erroCodigo,
                'erro_mensagem' => $erroMensagem,
            ],
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'retention_days' => 365,
        ]);
    }
}
