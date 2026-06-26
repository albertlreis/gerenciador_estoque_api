<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Mappers\ContaAzulBaixaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulBaixaContaPagarMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulContaPagarMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulPedidoMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulPessoaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulProdutoMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulTituloMapper;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\Cliente;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\AuditoriaLogService;

class ExportacaoContaAzulService
{
    public function __construct(
        private readonly array $config,
        private readonly ContaAzulConnectionService $connections,
        private readonly ContaAzulClient $client,
        private readonly ContaAzulPessoaMapper $pessoaMapper,
        private readonly ContaAzulProdutoMapper $produtoMapper,
        private readonly ContaAzulPedidoMapper $pedidoMapper,
        private readonly ContaAzulTituloMapper $tituloMapper,
        private readonly ContaAzulBaixaMapper $baixaMapper,
        private readonly ContaAzulContaPagarMapper $contaPagarMapper,
        private readonly ContaAzulBaixaContaPagarMapper $baixaContaPagarMapper
    ) {
    }

    public function exportarCliente(ContaAzulConexao $conexao, Cliente $cliente, ?int $lojaId = null): void
    {
        $path = (string) (($this->config['paths']['pessoas'] ?? '/v1/pessoas'));
        $this->exportJsonEntity(
            $conexao,
            $path,
            ContaAzulEntityType::PESSOA,
            (int) $cliente->id,
            $this->pessoaMapper->fromLocal($cliente),
            $lojaId
        );
    }

    public function exportarProduto(ContaAzulConexao $conexao, Produto $produto, ?ProdutoVariacao $variacao = null, ?int $lojaId = null): void
    {
        $path = (string) (($this->config['paths']['produtos'] ?? '/v1/produtos'));
        $this->exportJsonEntity(
            $conexao,
            $path,
            ContaAzulEntityType::PRODUTO,
            (int) $produto->id,
            $this->produtoMapper->fromLocal($produto, $variacao),
            $lojaId
        );
    }

    public function exportarPedido(ContaAzulConexao $conexao, Pedido $pedido, ?int $lojaId = null): void
    {
        $pedido->loadMissing(['itens.variacao.produto', 'cliente']);
        $path = (string) ($this->config['paths']['venda_create'] ?? '/v1/venda');
        $payload = $this->pedidoMapper->fromLocal($pedido, $lojaId);
        $this->exportJsonEntity(
            $conexao,
            $path,
            ContaAzulEntityType::VENDA,
            (int) $pedido->id,
            $payload,
            $lojaId,
            true
        );
    }

    public function exportarTitulo(ContaAzulConexao $conexao, ContaReceber $conta, ?int $lojaId = null): void
    {
        $conta->loadMissing(['pedido.cliente']);
        $path = (string) ($this->config['paths']['titulos_create'] ?? '/v1/financeiro/eventos-financeiros/contas-a-receber');
        $this->exportJsonEntity(
            $conexao,
            $path,
            ContaAzulEntityType::TITULO,
            (int) $conta->id,
            $this->tituloMapper->fromLocal($conta, $lojaId),
            $lojaId,
            true
        );
    }

    public function exportarContaPagar(ContaAzulConexao $conexao, ContaPagar $conta, ?int $lojaId = null): void
    {
        $conta->loadMissing(['fornecedor', 'categoria', 'centroCusto']);
        $path = (string) ($this->config['paths']['contas_pagar_create'] ?? '/v1/financeiro/eventos-financeiros/contas-a-pagar');
        try {
            $payload = $this->contaPagarMapper->fromLocal($conta, $lojaId);
        } catch (ContaAzulException $e) {
            $this->registrarFalhaPreExport(
                ContaAzulEntityType::CONTA_PAGAR,
                (int) $conta->id,
                $lojaId,
                $e
            );

            throw $e;
        }

        $this->exportJsonEntity(
            $conexao,
            $path,
            ContaAzulEntityType::CONTA_PAGAR,
            (int) $conta->id,
            $payload,
            $lojaId,
            true
        );
    }

    public function exportarBaixa(ContaAzulConexao $conexao, ContaReceberPagamento $pagamento, ?int $lojaId = null): void
    {
        $pagamento->loadMissing('conta');
        $idTituloExt = ContaAzulMapeamento::idExternoPorLocal(
            ContaAzulEntityType::TITULO,
            (int) $pagamento->conta_receber_id,
            $lojaId
        );
        if ($idTituloExt === null || $idTituloExt === '') {
            throw new ContaAzulException(
                'Exportação de baixa bloqueada: a conta a receber (título) local ainda não possui id externo na Conta Azul. Exporte o título antes.'
            );
        }

        $path = (string) ($this->config['paths']['baixa_create'] ?? '/v1/financeiro/eventos-financeiros/parcelas/{parcela_id}/baixa');
        $path = str_replace('{parcela_id}', $idTituloExt, $path);
        $this->exportJsonEntity(
            $conexao,
            $path,
            ContaAzulEntityType::BAIXA,
            (int) $pagamento->id,
            $this->baixaMapper->fromLocal($pagamento, $lojaId),
            $lojaId,
            true
        );
    }

    public function exportarBaixaContaPagar(ContaAzulConexao $conexao, ContaPagarPagamento $pagamento, ?int $lojaId = null): void
    {
        $pagamento->loadMissing('conta');
        $contaPagarId = (int) $pagamento->conta_pagar_id;
        $idParcelaExt = $this->resolveParcelaContaPagarId($conexao, $contaPagarId, $lojaId);
        if ($idParcelaExt === null || $idParcelaExt === '') {
            throw new ContaAzulException(
                'Exportacao de baixa bloqueada: a conta a pagar local ainda nao possui parcela externa na Conta Azul. Exporte a conta a pagar antes.'
            );
        }

        $path = (string) ($this->config['paths']['baixa_create'] ?? '/v1/financeiro/eventos-financeiros/parcelas/{parcela_id}/baixa');
        $path = str_replace('{parcela_id}', $idParcelaExt, $path);
        $this->exportJsonEntity(
            $conexao,
            $path,
            ContaAzulEntityType::BAIXA_CONTA_PAGAR,
            (int) $pagamento->id,
            $this->baixaContaPagarMapper->fromLocal($pagamento, $lojaId),
            $lojaId,
            true
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function exportJsonEntity(
        ContaAzulConexao $conexao,
        string $path,
        string $tipoEntidade,
        int $idLocal,
        array $payload,
        ?int $lojaId,
        bool $postOnly = false
    ): void {
        if (!filter_var($this->config['flags']['exportacao_ativa'] ?? true, FILTER_VALIDATE_BOOL)) {
            throw new ContaAzulException('Exportação desativada por configuração.');
        }

        $token = $this->connections->getValidAccessToken($conexao);

        $existing = ContaAzulMapeamento::query()
            ->where('tipo_entidade', $tipoEntidade)
            ->where('id_local', $idLocal)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();

        $method = 'POST';
        $uri = $path;
        if (!$postOnly && $existing?->id_externo) {
            $method = 'PUT';
            $uri = rtrim($path, '/') . '/' . $existing->id_externo;
        }

        $res = $method === 'POST'
            ? $this->client->post(ltrim($uri, '/'), $token, $payload)
            : $this->client->put(ltrim($uri, '/'), $token, $payload);

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $json = is_array($res['json']) ? $res['json'] : [];

        $payloadResumo = json_encode(['method' => $method, 'path' => $uri], JSON_UNESCAPED_UNICODE);
        $respostaResumo = isset($res['body']) ? mb_substr((string) $res['body'], 0, 2000) : null;
        $erroMensagem = $ok ? null : 'HTTP ' . $res['status'];

        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => $ok ? 'info' : 'error',
            'modulo' => 'conta_azul',
            'acao' => 'export',
            'status' => $ok ? 'sucesso' : 'falha',
            'label' => 'Log de sincronizacao Conta Azul',
            'message' => $erroMensagem ?: $respostaResumo,
            'entity_type' => $tipoEntidade,
            'entity_id' => $idLocal,
            'context_json' => [
                'loja_id' => $lojaId,
                'tipo_entidade' => $tipoEntidade,
                'id_local' => $idLocal,
                'id_externo' => $existing?->id_externo,
                'direcao' => 'export',
                'tentativa' => 1,
                'payload_resumo' => $payloadResumo,
                'resposta_resumo' => $respostaResumo,
                'erro_codigo' => $ok ? null : (string) $res['status'],
                'erro_mensagem' => $erroMensagem,
            ],
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'retention_days' => 365,
        ]);

        if (!$ok) {
            throw new ContaAzulException('Falha na exportação: HTTP ' . $res['status']);
        }

        $extId = (string) ($json['id'] ?? $json['uuid'] ?? $existing?->id_externo ?? '');
        if ($tipoEntidade === ContaAzulEntityType::CONTA_PAGAR) {
            $extId = $this->extractEventoFinanceiroId($json) ?? $extId;
        }
        if ($extId !== '') {
            ContaAzulMapeamento::updateOrCreate(
                [
                    'loja_id' => $lojaId,
                    'tipo_entidade' => $tipoEntidade,
                    'id_local' => $idLocal,
                ],
                [
                    'id_externo' => $extId,
                    'origem_inicial' => 'export',
                    'sincronizado_em' => now(),
                    'metadata_json' => ['response' => $json],
                ]
            );
        }
    }

    private function registrarFalhaPreExport(
        string $tipoEntidade,
        int $idLocal,
        ?int $lojaId,
        ContaAzulException $e
    ): void {
        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => 'error',
            'modulo' => 'conta_azul',
            'acao' => 'export',
            'status' => 'falha',
            'label' => 'Falha ao preparar exportacao Conta Azul',
            'message' => $e->getMessage(),
            'entity_type' => $tipoEntidade,
            'entity_id' => $idLocal,
            'context_json' => [
                'loja_id' => $lojaId,
                'tipo_entidade' => $tipoEntidade,
                'id_local' => $idLocal,
                'direcao' => 'export',
                'tentativa' => 1,
                'erro_codigo' => $e->reason,
                'erro_mensagem' => $e->getMessage(),
                'erro_contexto' => $e->context,
            ],
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'retention_days' => 365,
        ]);
    }

    private function resolveParcelaContaPagarId(ContaAzulConexao $conexao, int $contaPagarId, ?int $lojaId): ?string
    {
        $mapping = ContaAzulMapeamento::query()
            ->where('tipo_entidade', ContaAzulEntityType::CONTA_PAGAR)
            ->where('id_local', $contaPagarId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();

        if (!$mapping?->id_externo) {
            return null;
        }

        $fromMetadata = $this->extractParcelaId($mapping->metadata_json['response'] ?? null);
        if ($fromMetadata) {
            return $fromMetadata;
        }

        $token = $this->connections->getValidAccessToken($conexao);
        $path = (string) ($this->config['paths']['parcelas_by_evento'] ?? '/v1/financeiro/eventos-financeiros/{id_evento}/parcelas');
        $path = str_replace('{id_evento}', (string) $mapping->id_externo, $path);
        $res = $this->client->get(ltrim($path, '/'), $token);
        $ok = $res['status'] >= 200 && $res['status'] < 300;
        if (!$ok) {
            throw new ContaAzulException('Falha ao consultar parcelas da conta a pagar: HTTP ' . $res['status']);
        }

        return $this->extractParcelaId($res['json']);
    }

    private function extractEventoFinanceiroId(mixed $payload): ?string
    {
        return $this->firstStringByKeys($payload, [
            'id_evento_financeiro',
            'idEventoFinanceiro',
            'evento_financeiro_id',
            'eventoFinanceiroId',
            'id_evento',
            'idEvento',
            'id',
            'uuid',
        ]);
    }

    private function extractParcelaId(mixed $payload): ?string
    {
        return $this->firstStringByKeys($payload, [
            'id_parcela',
            'idParcela',
            'parcela_id',
            'parcelaId',
        ]) ?? $this->firstListItemId($payload);
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstStringByKeys(mixed $payload, array $keys): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        foreach ($payload as $value) {
            $found = $this->firstStringByKeys($value, $keys);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function firstListItemId(mixed $payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        $list = array_is_list($payload)
            ? $payload
            : ($payload['data'] ?? $payload['items'] ?? $payload['parcelas'] ?? null);

        if (is_array($list) && isset($list[0]) && is_array($list[0])) {
            foreach (['id', 'uuid'] as $key) {
                if (isset($list[0][$key]) && is_scalar($list[0][$key]) && (string) $list[0][$key] !== '') {
                    return (string) $list[0][$key];
                }
            }
        }

        foreach ($payload as $value) {
            $found = $this->firstListItemId($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
