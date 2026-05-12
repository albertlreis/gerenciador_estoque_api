<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulLocalCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContaAzulPendenciasServiceTest extends TestCase
{
    use RefreshDatabase;

    private function insertStaging(string $table, string $externalId, array $payload, string $status = 'novo'): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return DB::table($table)->insertGetId([
            'loja_id' => null,
            'identificador_externo' => $externalId,
            'payload_json' => $json,
            'hash_payload' => hash('sha256', (string) $json),
            'status_conciliacao' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCliente(array $overrides = []): int
    {
        return DB::table('clientes')->insertGetId(array_merge([
            'nome' => 'Cliente Teste',
            'documento' => null,
            'email' => null,
            'telefone' => null,
            'whatsapp' => null,
            'tipo' => 'pf',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createProduto(array $overrides = []): int
    {
        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Teste ' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('produtos')->insertGetId(array_merge([
            'nome' => 'Produto Teste',
            'id_categoria' => $categoriaId,
            'codigo_produto' => null,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createUsuario(): int
    {
        return DB::table('acesso_usuarios')->insertGetId([
            'nome' => 'Usuario Teste',
            'email' => 'usuario-' . uniqid() . '@teste.local',
            'senha' => bcrypt('secret'),
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPedido(int $clienteId, array $overrides = []): int
    {
        return DB::table('pedidos')->insertGetId(array_merge([
            'id_cliente' => $clienteId,
            'id_usuario' => $this->createUsuario(),
            'tipo' => 'venda',
            'numero_externo' => null,
            'data_pedido' => '2026-05-10 09:00:00',
            'valor_total' => 150.00,
            'prazo_dias_uteis' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createContaFinanceira(): int
    {
        return DB::table('contas_financeiras')->insertGetId([
            'nome' => 'Conta Teste',
            'slug' => 'conta-' . uniqid(),
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMapeamento(string $tipo, string $externalId, int $localId): void
    {
        ContaAzulMapeamento::create([
            'loja_id' => null,
            'tipo_entidade' => $tipo,
            'id_local' => $localId,
            'id_externo' => $externalId,
            'origem_inicial' => 'manual',
            'sincronizado_em' => now(),
        ]);
    }

    public function test_lista_e_vincula_pendencia_manual(): void
    {
        $id = DB::table('stg_conta_azul_pessoas')->insertGetId([
            'loja_id' => null,
            'identificador_externo' => 'cliente-ca-1',
            'payload_json' => json_encode(['nome' => 'Cliente Teste', 'documento' => '12345678901'], JSON_UNESCAPED_UNICODE),
            'hash_payload' => hash('sha256', 'cliente-ca-1'),
            'status_conciliacao' => 'pendente',
            'observacao_conciliacao' => 'Sem documento local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ConciliacaoContaAzulService::class);
        $pendencias = $service->listarPendenciasDetalhadas(null, 'pessoas')['data'];

        $this->assertCount(1, $pendencias);
        $this->assertSame('pessoa', $pendencias[0]['entidade']);
        $this->assertSame('Cliente Teste', $pendencias[0]['payload_resumo']['nome']);

        $resultado = $service->resolverPendencia('pessoas', $id, null, 'vincular', 55, 'Vinculo conferido');

        $this->assertSame('conciliado', $resultado['status']);
        $this->assertDatabaseHas('stg_conta_azul_pessoas', [
            'id' => $id,
            'status_conciliacao' => 'conciliado',
        ]);
        $this->assertTrue(ContaAzulMapeamento::query()
            ->where('tipo_entidade', 'pessoa')
            ->where('id_local', 55)
            ->where('id_externo', 'cliente-ca-1')
            ->exists());
    }

    public function test_nao_vincula_nota_em_modo_read_only(): void
    {
        $id = DB::table('stg_conta_azul_notas')->insertGetId([
            'loja_id' => null,
            'identificador_externo' => 'nota-ca-1',
            'payload_json' => json_encode(['numero' => '123'], JSON_UNESCAPED_UNICODE),
            'hash_payload' => hash('sha256', 'nota-ca-1'),
            'status_conciliacao' => 'pendente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ContaAzulException::class);
        $this->expectExceptionMessage('Notas fiscais Conta Azul estao em modo somente leitura');

        app(ConciliacaoContaAzulService::class)->resolverPendencia('notas', $id, null, 'vincular', 99);
    }

    public function test_pessoas_auto_conciliam_por_documento_exato(): void
    {
        $clienteId = $this->createCliente([
            'nome' => 'Cliente Documento',
            'documento' => '123.456.789-01',
        ]);
        $id = $this->insertStaging('stg_conta_azul_pessoas', 'cliente-doc-1', [
            'nome' => 'Cliente Documento',
            'documento' => '12345678901',
        ]);

        $resultado = app(ConciliacaoContaAzulService::class)->conciliarPessoas();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertDatabaseHas('stg_conta_azul_pessoas', [
            'id' => $id,
            'status_conciliacao' => 'conciliado',
            'candidato_id_local' => $clienteId,
            'candidato_score' => 100,
            'conciliacao_origem' => 'auto',
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::PESSOA,
            'id_local' => $clienteId,
            'id_externo' => 'cliente-doc-1',
        ]);
    }

    public function test_produtos_auto_conciliam_por_sku_exato(): void
    {
        $produtoId = $this->createProduto(['nome' => 'Cadeira Oslo']);
        DB::table('produto_variacoes')->insert([
            'produto_id' => $produtoId,
            'referencia' => 'OSLO-001',
            'nome' => 'Cadeira Oslo Natural',
            'sku_interno' => 'SKU-OSLO-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = $this->insertStaging('stg_conta_azul_produtos', 'produto-sku-1', [
            'nome' => 'Cadeira Oslo',
            'sku' => 'SKU-OSLO-001',
        ]);

        $resultado = app(ConciliacaoContaAzulService::class)->conciliarProdutos();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertDatabaseHas('stg_conta_azul_produtos', [
            'id' => $id,
            'status_conciliacao' => 'conciliado',
            'candidato_id_local' => $produtoId,
            'candidato_score' => 100,
            'conciliacao_origem' => 'auto',
        ]);
    }

    public function test_vendas_auto_conciliam_por_cliente_mapeado_data_e_total_unicos(): void
    {
        $clienteId = $this->createCliente(['nome' => 'Cliente Venda']);
        $pedidoId = $this->createPedido($clienteId, [
            'data_pedido' => '2026-05-10 14:00:00',
            'valor_total' => 150.00,
        ]);
        $this->createMapeamento(ContaAzulEntityType::PESSOA, 'cliente-ca-venda-1', $clienteId);
        $id = $this->insertStaging('stg_conta_azul_vendas', 'venda-ca-1', [
            'idCliente' => 'cliente-ca-venda-1',
            'data' => '2026-05-10',
            'valorTotal' => '150,00',
        ]);

        $resultado = app(ConciliacaoContaAzulService::class)->conciliarVendas();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertDatabaseHas('stg_conta_azul_vendas', [
            'id' => $id,
            'status_conciliacao' => 'conciliado',
            'candidato_id_local' => $pedidoId,
            'candidato_score' => 96,
            'conciliacao_origem' => 'auto',
        ]);
    }

    public function test_titulos_e_baixas_auto_conciliam_quando_dependencias_estao_mapeadas(): void
    {
        $clienteId = $this->createCliente(['nome' => 'Cliente Financeiro']);
        $pedidoId = $this->createPedido($clienteId, ['valor_total' => 250.00]);
        $contaFinanceiraId = $this->createContaFinanceira();
        $contaId = DB::table('contas_receber')->insertGetId([
            'pedido_id' => $pedidoId,
            'descricao' => 'Parcela Conta Azul',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-20',
            'valor_bruto' => 250.00,
            'valor_liquido' => 250.00,
            'valor_recebido' => 0,
            'saldo_aberto' => 250.00,
            'status' => 'ABERTA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pagamentoId = DB::table('contas_receber_pagamentos')->insertGetId([
            'conta_receber_id' => $contaId,
            'data_pagamento' => '2026-05-21',
            'valor' => 250.00,
            'forma_pagamento' => 'pix',
            'conta_financeira_id' => $contaFinanceiraId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createMapeamento(ContaAzulEntityType::VENDA, 'venda-ca-fin-1', $pedidoId);
        $this->createMapeamento(ContaAzulEntityType::CONTA_FINANCEIRA, 'conta-ca-fin-1', $contaFinanceiraId);

        $tituloStagingId = $this->insertStaging('stg_conta_azul_financeiro', 'titulo-ca-1', [
            'idVenda' => 'venda-ca-fin-1',
            'dataVencimento' => '2026-05-20',
            'valor' => '250.00',
        ]);

        $resultadoTitulos = app(ConciliacaoContaAzulService::class)->conciliarTitulos();

        $this->assertSame(1, $resultadoTitulos['conciliados']);
        $this->assertDatabaseHas('stg_conta_azul_financeiro', [
            'id' => $tituloStagingId,
            'status_conciliacao' => 'conciliado',
            'candidato_id_local' => $contaId,
            'candidato_score' => 97,
            'conciliacao_origem' => 'auto',
        ]);

        $baixaStagingId = $this->insertStaging('stg_conta_azul_baixas', 'baixa-ca-1', [
            'idTitulo' => 'titulo-ca-1',
            'idContaFinanceira' => 'conta-ca-fin-1',
            'dataPagamento' => '2026-05-21',
            'valor' => '250.00',
        ]);

        $resultadoBaixas = app(ConciliacaoContaAzulService::class)->conciliarBaixas();

        $this->assertSame(1, $resultadoBaixas['conciliados']);
        $this->assertDatabaseHas('stg_conta_azul_baixas', [
            'id' => $baixaStagingId,
            'status_conciliacao' => 'conciliado',
            'candidato_id_local' => $pagamentoId,
            'candidato_score' => 97,
            'conciliacao_origem' => 'auto',
        ]);
    }

    public function test_candidatos_entre_80_e_94_ficam_pendentes_com_sugestao(): void
    {
        $produtoId = $this->createProduto(['nome' => 'Mesa Lina']);
        $id = $this->insertStaging('stg_conta_azul_produtos', 'produto-nome-1', [
            'nome' => 'mesa lina',
        ]);

        $resultado = app(ConciliacaoContaAzulService::class)->conciliarProdutos();

        $this->assertSame(1, $resultado['pendentes']);
        $this->assertDatabaseHas('stg_conta_azul_produtos', [
            'id' => $id,
            'status_conciliacao' => 'pendente',
            'candidato_id_local' => $produtoId,
            'candidato_score' => 85,
            'conciliacao_origem' => 'sugerido',
        ]);
    }

    public function test_candidatos_ambiguos_viram_conflito(): void
    {
        $this->createProduto(['nome' => 'Banco Teca']);
        $this->createProduto(['nome' => 'Banco Teca']);
        $id = $this->insertStaging('stg_conta_azul_produtos', 'produto-conflito-1', [
            'nome' => 'Banco Teca',
        ]);

        $resultado = app(ConciliacaoContaAzulService::class)->conciliarProdutos();

        $this->assertSame(1, $resultado['conflitos']);
        $row = DB::table('stg_conta_azul_produtos')->where('id', $id)->first();
        $this->assertSame('conflito', $row->status_conciliacao);
        $this->assertSame('conflito', $row->conciliacao_origem);
        $this->assertSame(85, (int) $row->candidato_score);
        $this->assertCount(2, json_decode((string) $row->candidato_json, true));
    }

    public function test_resolucao_manual_vira_mapeamento_reutilizado_em_conciliacao_posterior(): void
    {
        $clienteId = $this->createCliente(['nome' => 'Cliente Manual']);
        $id = $this->insertStaging('stg_conta_azul_pessoas', 'cliente-manual-1', [
            'nome' => 'Cliente Manual',
        ], 'pendente');
        $service = app(ConciliacaoContaAzulService::class);

        $service->resolverPendencia('pessoas', $id, null, 'vincular', $clienteId, 'Conferido manualmente');
        DB::table('stg_conta_azul_pessoas')->where('id', $id)->update([
            'status_conciliacao' => 'novo',
            'candidato_id_local' => null,
            'candidato_score' => null,
            'candidato_motivo' => null,
            'candidato_json' => null,
            'conciliacao_origem' => null,
        ]);

        $resultado = $service->conciliarPessoas();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertDatabaseHas('stg_conta_azul_pessoas', [
            'id' => $id,
            'status_conciliacao' => 'conciliado',
            'candidato_id_local' => $clienteId,
            'candidato_score' => 100,
            'candidato_motivo' => 'Mapeamento existente',
            'conciliacao_origem' => 'auto',
        ]);
    }

    public function test_cria_cliente_a_partir_de_pessoa_conta_azul(): void
    {
        $id = $this->insertStaging('stg_conta_azul_pessoas', 'pessoa-criar-cliente-1', [
            'nome' => 'Cliente Criado',
            'documento' => '12345678901',
            'email' => 'cliente@teste.local',
        ]);

        $resultado = app(ContaAzulLocalCreationService::class)->criarLocal('pessoa', $id, null, [
            'tipo_local' => 'cliente',
            'dados' => [
                'tipo' => 'pf',
                'nome' => 'Cliente Criado',
                'documento' => '12345678901',
                'email' => 'cliente@teste.local',
            ],
        ]);

        $this->assertSame('cliente', $resultado['tipo_local']);
        $this->assertDatabaseHas('clientes', ['id' => $resultado['id_local'], 'nome' => 'Cliente Criado']);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::PESSOA,
            'id_local' => $resultado['id_local'],
            'id_externo' => 'pessoa-criar-cliente-1',
        ]);
        $this->assertDatabaseHas('stg_conta_azul_pessoas', [
            'id' => $id,
            'status_conciliacao' => 'conciliado',
            'conciliacao_origem' => 'manual_criacao',
        ]);
    }

    public function test_cria_fornecedor_a_partir_de_pessoa_conta_azul(): void
    {
        $id = $this->insertStaging('stg_conta_azul_pessoas', 'pessoa-criar-fornecedor-1', [
            'nome' => 'Fornecedor Criado',
            'cnpj' => '12345678000190',
        ]);

        $resultado = app(ContaAzulLocalCreationService::class)->criarLocal('pessoas', $id, null, [
            'tipo_local' => 'fornecedor',
            'dados' => [
                'nome' => 'Fornecedor Criado',
                'cnpj' => '12345678000190',
            ],
        ]);

        $this->assertSame('fornecedor', $resultado['tipo_local']);
        $this->assertDatabaseHas('fornecedores', ['id' => $resultado['id_local'], 'nome' => 'Fornecedor Criado']);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::FORNECEDOR,
            'id_local' => $resultado['id_local'],
            'id_externo' => 'pessoa-criar-fornecedor-1',
        ]);
    }

    public function test_cria_conta_receber_com_baixa_parcial_a_partir_de_titulo(): void
    {
        $contaFinanceiraId = $this->createContaFinanceira();
        $id = $this->insertStaging('stg_conta_azul_financeiro', 'titulo-criar-receber-1', [
            'descricao' => 'Parcela Conta Azul',
            'numero' => 'REC-001',
            'dataVencimento' => '2026-05-20',
            'valor' => '300.00',
            'valorPago' => '120.00',
        ]);

        $resultado = app(ContaAzulLocalCreationService::class)->criarLocal('titulo', $id, null, [
            'tipo_local' => 'conta_receber',
            'dados' => [
                'descricao' => 'Parcela Conta Azul',
                'numero_documento' => 'REC-001',
                'data_vencimento' => '2026-05-20',
                'valor_bruto' => '300.00',
            ],
            'baixa' => [
                'data_pagamento' => '2026-05-21',
                'valor' => '120.00',
                'forma_pagamento' => 'PIX',
                'conta_financeira_id' => $contaFinanceiraId,
            ],
        ]);

        $this->assertSame('conta_receber', $resultado['tipo_local']);
        $this->assertDatabaseHas('contas_receber', [
            'id' => $resultado['id_local'],
            'descricao' => 'Parcela Conta Azul',
            'status' => 'PARCIAL',
        ]);
        $this->assertDatabaseHas('contas_receber_pagamentos', [
            'conta_receber_id' => $resultado['id_local'],
            'valor' => '120.00',
            'conta_financeira_id' => $contaFinanceiraId,
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::TITULO,
            'id_local' => $resultado['id_local'],
            'id_externo' => 'titulo-criar-receber-1',
        ]);
    }

    public function test_cria_conta_pagar_com_fornecedor_e_baixa_total(): void
    {
        $contaFinanceiraId = $this->createContaFinanceira();
        $id = $this->insertStaging('stg_conta_azul_contas_pagar', 'titulo-criar-pagar-1', [
            'descricao' => 'Conta fornecedor',
            'numero' => 'PAG-001',
            'dataVencimento' => '2026-05-22',
            'valor' => '180.00',
            'status' => 'PAGO',
            'fornecedor' => ['nome' => 'Fornecedor CA', 'cnpj' => '12345678000190'],
        ]);

        $resultado = app(ContaAzulLocalCreationService::class)->criarLocal('contas-pagar', $id, null, [
            'tipo_local' => 'conta_pagar',
            'dados' => [
                'descricao' => 'Conta fornecedor',
                'numero_documento' => 'PAG-001',
                'data_vencimento' => '2026-05-22',
                'valor_bruto' => '180.00',
            ],
            'pessoa' => [
                'modo' => 'criar',
                'tipo_local' => 'fornecedor',
                'identificador_externo' => 'fornecedor-ca-1',
                'dados' => ['nome' => 'Fornecedor CA', 'cnpj' => '12345678000190'],
            ],
            'baixa' => [
                'data_pagamento' => '2026-05-22',
                'valor' => '180.00',
                'forma_pagamento' => 'BOLETO',
                'conta_financeira_id' => $contaFinanceiraId,
            ],
        ]);

        $this->assertSame('conta_pagar', $resultado['tipo_local']);
        $this->assertDatabaseHas('contas_pagar', [
            'id' => $resultado['id_local'],
            'descricao' => 'Conta fornecedor',
            'status' => 'PAGA',
        ]);
        $this->assertDatabaseHas('contas_pagar_pagamentos', [
            'conta_pagar_id' => $resultado['id_local'],
            'valor' => '180.00',
            'conta_financeira_id' => $contaFinanceiraId,
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::CONTA_PAGAR,
            'id_local' => $resultado['id_local'],
            'id_externo' => 'titulo-criar-pagar-1',
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::FORNECEDOR,
            'id_externo' => 'fornecedor-ca-1',
        ]);
    }

    public function test_bloqueia_criacao_financeira_paga_sem_conta_financeira_e_forma(): void
    {
        $id = $this->insertStaging('stg_conta_azul_financeiro', 'titulo-sem-baixa-1', [
            'descricao' => 'Parcela paga',
            'dataVencimento' => '2026-05-20',
            'valor' => '100.00',
            'status' => 'PAGO',
        ]);

        $this->expectException(ValidationException::class);

        app(ContaAzulLocalCreationService::class)->criarLocal('titulo', $id, null, [
            'tipo_local' => 'conta_receber',
            'dados' => [
                'descricao' => 'Parcela paga',
                'data_vencimento' => '2026-05-20',
                'valor_bruto' => '100.00',
            ],
        ]);
    }
}
