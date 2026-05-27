<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Mappers\ContaAzulBaixaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulPedidoMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulPessoaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulProdutoMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulTituloMapper;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\Cliente;
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
        private readonly ContaAzulBaixaMapper $baixaMapper
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
}
