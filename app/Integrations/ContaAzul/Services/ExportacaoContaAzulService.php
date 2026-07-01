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
        $contaReceberId = (int) $pagamento->conta_receber_id;
        $idParcelaExt = $this->resolveParcelaTituloId($conexao, $contaReceberId, $lojaId);
        if ($idParcelaExt === null || $idParcelaExt === '') {
            throw new ContaAzulException(
                'Exportacao de baixa bloqueada: a conta a receber local ainda nao possui parcela externa na Conta Azul. Exporte o titulo antes.'
            );
        }

        $path = (string) ($this->config['paths']['baixa_create'] ?? '/v1/financeiro/eventos-financeiros/parcelas/{parcela_id}/baixa');
        $path = str_replace('{parcela_id}', $idParcelaExt, $path);
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

    public function estornarBaixaMapeada(ContaAzulConexao $conexao, string $tipoEntidade, int $idLocal, ?int $lojaId = null): void
    {
        if (!in_array($tipoEntidade, [ContaAzulEntityType::BAIXA, ContaAzulEntityType::BAIXA_CONTA_PAGAR], true)) {
            throw new ContaAzulException('Tipo de baixa Conta Azul invalido para estorno.');
        }

        $mapping = $this->buscarMapeamento($tipoEntidade, $idLocal, $lojaId);
        if (!$mapping?->id_externo) {
            $this->registrarAuditoriaOperacao(
                'estorno_baixa',
                $tipoEntidade,
                $idLocal,
                $lojaId,
                'ignorado',
                'Mapeamento da baixa Conta Azul nao encontrado.',
                ['motivo' => 'mapeamento_nao_encontrado']
            );

            return;
        }

        $path = (string) ($this->config['paths']['baixa_delete'] ?? '/v1/financeiro/eventos-financeiros/parcelas/baixa/{baixa_id}');
        $path = $this->replacePathIds($path, (string) $mapping->id_externo);
        $token = $this->connections->getValidAccessToken($conexao);
        $res = $this->client->delete(ltrim($path, '/'), $token);
        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $notFound = (int) $res['status'] === 404;
        $respostaResumo = isset($res['body']) ? mb_substr((string) $res['body'], 0, 2000) : null;

        if ($ok || $notFound) {
            $mapping->delete();
            $this->registrarAuditoriaOperacao(
                'estorno_baixa',
                $tipoEntidade,
                $idLocal,
                $lojaId,
                'sucesso',
                $notFound ? 'Baixa externa ja ausente na Conta Azul.' : 'Baixa externa estornada na Conta Azul.',
                [
                    'method' => 'DELETE',
                    'path' => $path,
                    'id_externo' => $mapping->id_externo,
                    'http_status' => $res['status'],
                    'resposta_resumo' => $respostaResumo,
                    'resultado' => $notFound ? 'ja_ausente' : 'removida',
                ]
            );

            return;
        }

        $this->registrarAuditoriaOperacao(
            'estorno_baixa',
            $tipoEntidade,
            $idLocal,
            $lojaId,
            'falha',
            'Falha ao estornar baixa externa na Conta Azul: HTTP ' . $res['status'],
            [
                'method' => 'DELETE',
                'path' => $path,
                'id_externo' => $mapping->id_externo,
                'http_status' => $res['status'],
                'resposta_resumo' => $respostaResumo,
            ]
        );

        throw new ContaAzulException('Falha ao estornar baixa externa na Conta Azul: HTTP ' . $res['status']);
    }

    public function excluirTituloFinanceiroMapeado(ContaAzulConexao $conexao, string $tipoEntidade, int $idLocal, ?int $lojaId = null): void
    {
        $pathKey = match ($tipoEntidade) {
            ContaAzulEntityType::TITULO => 'titulos_delete',
            ContaAzulEntityType::CONTA_PAGAR => 'contas_pagar_delete',
            default => null,
        };

        if ($pathKey === null) {
            throw new ContaAzulException('Tipo de titulo financeiro Conta Azul invalido para exclusao.');
        }

        $pathConfig = $this->config['paths'][$pathKey] ?? null;
        if (!is_string($pathConfig) || trim($pathConfig) === '') {
            $this->registrarAuditoriaOperacao(
                'delete_titulo',
                $tipoEntidade,
                $idLocal,
                $lojaId,
                'ignorado',
                'Endpoint de exclusao de titulo Conta Azul nao configurado.',
                ['motivo' => 'endpoint_nao_configurado', 'path_key' => $pathKey]
            );

            return;
        }

        $mapping = $this->buscarMapeamento($tipoEntidade, $idLocal, $lojaId);
        if (!$mapping?->id_externo) {
            $this->registrarAuditoriaOperacao(
                'delete_titulo',
                $tipoEntidade,
                $idLocal,
                $lojaId,
                'ignorado',
                'Mapeamento do titulo Conta Azul nao encontrado.',
                ['motivo' => 'mapeamento_nao_encontrado', 'path_key' => $pathKey]
            );

            return;
        }

        $path = $this->replacePathIds($pathConfig, (string) $mapping->id_externo);
        $token = $this->connections->getValidAccessToken($conexao);
        $res = $this->client->delete(ltrim($path, '/'), $token);
        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $notFound = (int) $res['status'] === 404;
        $respostaResumo = isset($res['body']) ? mb_substr((string) $res['body'], 0, 2000) : null;

        if ($ok || $notFound) {
            $mapping->delete();
            $this->registrarAuditoriaOperacao(
                'delete_titulo',
                $tipoEntidade,
                $idLocal,
                $lojaId,
                'sucesso',
                $notFound ? 'Titulo externo ja ausente na Conta Azul.' : 'Titulo externo removido na Conta Azul.',
                [
                    'method' => 'DELETE',
                    'path' => $path,
                    'id_externo' => $mapping->id_externo,
                    'http_status' => $res['status'],
                    'resposta_resumo' => $respostaResumo,
                    'resultado' => $notFound ? 'ja_ausente' : 'removido',
                ]
            );

            return;
        }

        $this->registrarAuditoriaOperacao(
            'delete_titulo',
            $tipoEntidade,
            $idLocal,
            $lojaId,
            'falha',
            'Falha ao remover titulo externo na Conta Azul: HTTP ' . $res['status'],
            [
                'method' => 'DELETE',
                'path' => $path,
                'id_externo' => $mapping->id_externo,
                'http_status' => $res['status'],
                'resposta_resumo' => $respostaResumo,
            ]
        );

        throw new ContaAzulException('Falha ao remover titulo externo na Conta Azul: HTTP ' . $res['status']);
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

    private function resolveParcelaTituloId(ContaAzulConexao $conexao, int $contaReceberId, ?int $lojaId): ?string
    {
        $mapping = $this->buscarMapeamento(ContaAzulEntityType::TITULO, $contaReceberId, $lojaId);

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
            throw new ContaAzulException('Falha ao consultar parcelas da conta a receber: HTTP ' . $res['status']);
        }

        return $this->extractParcelaId($res['json']);
    }

    private function resolveParcelaContaPagarId(ContaAzulConexao $conexao, int $contaPagarId, ?int $lojaId): ?string
    {
        $mapping = $this->buscarMapeamento(ContaAzulEntityType::CONTA_PAGAR, $contaPagarId, $lojaId);

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

    private function buscarMapeamento(string $tipoEntidade, int $idLocal, ?int $lojaId): ?ContaAzulMapeamento
    {
        return ContaAzulMapeamento::query()
            ->where('tipo_entidade', $tipoEntidade)
            ->where('id_local', $idLocal)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();
    }

    /**
     * @param array<string, mixed> $contexto
     */
    private function registrarAuditoriaOperacao(
        string $acao,
        string $tipoEntidade,
        int $idLocal,
        ?int $lojaId,
        string $status,
        ?string $mensagem,
        array $contexto = []
    ): void {
        $payloadResumo = json_encode($contexto, JSON_UNESCAPED_UNICODE);

        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => $status === 'falha' ? 'error' : 'info',
            'modulo' => 'conta_azul',
            'acao' => $acao,
            'status' => $status,
            'label' => 'Log de sincronizacao Conta Azul',
            'message' => $mensagem,
            'entity_type' => $tipoEntidade,
            'entity_id' => $idLocal,
            'context_json' => [
                'loja_id' => $lojaId,
                'tipo_entidade' => $tipoEntidade,
                'id_local' => $idLocal,
                'id_externo' => $contexto['id_externo'] ?? null,
                'direcao' => 'export',
                'tentativa' => 1,
                'payload_resumo' => $payloadResumo,
                'resposta_resumo' => $contexto['resposta_resumo'] ?? $mensagem,
                'erro_codigo' => $status === 'falha' ? (string) ($contexto['http_status'] ?? '') : null,
                'erro_mensagem' => $status === 'falha' ? $mensagem : null,
                'motivo' => $contexto['motivo'] ?? null,
                'resultado' => $contexto['resultado'] ?? null,
            ],
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'retention_days' => 365,
        ]);
    }

    private function replacePathIds(string $path, string $idExterno): string
    {
        return str_replace(
            ['{id}', '{uuid}', '{id_evento}', '{evento_id}', '{titulo_id}', '{conta_pagar_id}', '{baixa_id}'],
            $idExterno,
            $path
        );
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
