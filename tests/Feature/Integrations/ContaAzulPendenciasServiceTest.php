<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulLocalCreationService;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
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

    private function autenticar(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Conta Azul Teste',
            'email' => 'conta-azul-' . uniqid() . '@teste.local',
            'senha' => 'secret',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
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

    public function test_vendas_com_numero_externo_duplicado_viram_conflito(): void
    {
        $clienteId = $this->createCliente(['nome' => 'Cliente Venda Duplicada']);
        $this->createPedido($clienteId, ['numero_externo' => 'CA-DUP-001']);
        $this->createPedido($clienteId, ['numero_externo' => 'CA-DUP-001']);

        $id = $this->insertStaging('stg_conta_azul_vendas', 'venda-ca-dup-1', [
            'numero' => 'CA-DUP-001',
        ]);

        $resultado = app(ConciliacaoContaAzulService::class)->conciliarVendas();

        $this->assertSame(1, $resultado['conflitos']);

        $row = DB::table('stg_conta_azul_vendas')->where('id', $id)->first();
        $this->assertSame('conflito', $row->status_conciliacao);
        $this->assertSame('conflito', $row->conciliacao_origem);
        $this->assertStringContainsString('mesmo número externo', (string) $row->observacao_conciliacao);
        $this->assertCount(2, json_decode((string) $row->candidato_json, true));
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
        $this->autenticar();
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
        $this->assertDatabaseHas('lancamentos_financeiros', [
            'referencia_type' => 'App\Models\ContaReceber',
            'referencia_id' => $resultado['id_local'],
            'tipo' => 'receita',
            'valor' => '120.00',
            'conta_id' => $contaFinanceiraId,
            'status' => 'confirmado',
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::TITULO,
            'id_local' => $resultado['id_local'],
            'id_externo' => 'titulo-criar-receber-1',
        ]);

        $this->getJson('/api/v1/financeiro/contas-receber?busca=Parcela%20Conta%20Azul')
            ->assertOk()
            ->assertJsonFragment(['descricao' => 'Parcela Conta Azul']);
        $this->getJson('/api/v1/financeiro/lancamentos?q=Recebimento%20Conta%20a%20Receber')
            ->assertOk()
            ->assertJsonFragment(['tipo' => 'receita'])
            ->assertJsonFragment(['valor' => '120.00']);
    }

    public function test_cria_conta_pagar_com_fornecedor_e_baixa_total(): void
    {
        $this->autenticar();
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
        $this->assertDatabaseHas('lancamentos_financeiros', [
            'referencia_type' => 'App\Models\ContaPagar',
            'referencia_id' => $resultado['id_local'],
            'tipo' => 'despesa',
            'valor' => '180.00',
            'conta_id' => $contaFinanceiraId,
            'status' => 'confirmado',
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

        $this->getJson('/api/v1/financeiro/contas-pagar?busca=Conta%20fornecedor')
            ->assertOk()
            ->assertJsonFragment(['descricao' => 'Conta fornecedor']);
        $this->getJson('/api/v1/financeiro/lancamentos?q=Pagamento%20Conta%20a%20Pagar')
            ->assertOk()
            ->assertJsonFragment(['tipo' => 'despesa'])
            ->assertJsonFragment(['valor' => '180.00']);
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

    public function test_cria_conta_receber_em_aberto_visivel_sem_lancamento_financeiro(): void
    {
        $this->autenticar();
        $id = $this->insertStaging('stg_conta_azul_financeiro', 'titulo-aberto-visivel-1', [
            'descricao' => 'Titulo Aberto Visivel',
            'numero' => 'REC-ABERTO-001',
            'dataVencimento' => '2026-05-25',
            'valor' => '210.00',
        ]);

        $resultado = app(ContaAzulLocalCreationService::class)->criarLocal('titulo', $id, null, [
            'tipo_local' => 'conta_receber',
            'dados' => [
                'descricao' => 'Titulo Aberto Visivel',
                'numero_documento' => 'REC-ABERTO-001',
                'data_vencimento' => '2026-05-25',
                'valor_bruto' => '210.00',
            ],
        ]);

        $this->assertDatabaseHas('contas_receber', [
            'id' => $resultado['id_local'],
            'descricao' => 'Titulo Aberto Visivel',
            'status' => 'ABERTA',
        ]);
        $this->assertDatabaseMissing('lancamentos_financeiros', [
            'referencia_type' => 'App\Models\ContaReceber',
            'referencia_id' => $resultado['id_local'],
        ]);

        $this->getJson('/api/v1/financeiro/contas-receber?busca=Titulo%20Aberto%20Visivel')
            ->assertOk()
            ->assertJsonFragment(['descricao' => 'Titulo Aberto Visivel']);
        $this->getJson('/api/v1/financeiro/lancamentos?q=Titulo%20Aberto%20Visivel')
            ->assertOk()
            ->assertJsonMissing(['descricao' => 'Titulo Aberto Visivel']);
    }

    public function test_cria_lote_misto_com_entidades_importadas_da_conta_azul(): void
    {
        $this->autenticar();
        $pessoaId = $this->insertStaging('stg_conta_azul_pessoas', 'pessoa-lote-1', [
            'nome' => 'Cliente Lote',
            'documento' => '12345678901',
        ]);
        $produtoId = $this->insertStaging('stg_conta_azul_produtos', 'produto-lote-1', [
            'nome' => 'Produto Lote',
            'codigo' => 'SKU-LOTE-1',
            'preco' => '99.90',
        ]);
        $contaFinanceiraId = $this->insertStaging('stg_conta_azul_contas_financeiras', 'conta-lote-1', [
            'nome' => 'Banco Lote',
            'tipo' => 'banco',
        ]);
        $categoriaId = $this->insertStaging('stg_conta_azul_categorias_financeiras', 'categoria-lote-1', [
            'nome' => 'Receitas Lote',
            'tipo' => 'receita',
        ]);
        $centroId = $this->insertStaging('stg_conta_azul_centros_custo', 'centro-lote-1', [
            'nome' => 'Centro Lote',
        ]);
        $formaId = $this->insertStaging('stg_conta_azul_formas_pagamento', 'pix-lote', [
            'codigo' => 'PIX',
            'nome' => 'PIX',
        ]);
        $vendaId = $this->insertStaging('stg_conta_azul_vendas', 'venda-lote-1', [
            'numero' => 'VENDA-LOTE-1',
            'data' => '2026-05-13',
            'valorTotal' => '99.90',
            'cliente' => ['nome' => 'Cliente Venda Lote', 'documento' => '10987654321'],
            'itens' => [[
                'idProduto' => 'produto-venda-lote-1',
                'nome' => 'Produto Venda Lote',
                'codigo' => 'SKU-VENDA-1',
                'quantidade' => 1,
                'valorUnitario' => '99.90',
            ]],
        ]);
        $tituloId = $this->insertStaging('stg_conta_azul_financeiro', 'titulo-lote-1', [
            'descricao' => 'Titulo Lote',
            'dataVencimento' => '2026-05-20',
            'valor' => '100.00',
            'valorPago' => '40.00',
        ]);
        $contaPagarId = $this->insertStaging('stg_conta_azul_contas_pagar', 'pagar-lote-1', [
            'descricao' => 'Conta Pagar Lote',
            'dataVencimento' => '2026-05-22',
            'valor' => '80.00',
            'fornecedor' => ['nome' => 'Fornecedor Lote'],
        ]);
        $parcelaId = $this->insertStaging('stg_conta_azul_parcelas', 'parcela-lote-1', [
            'idEvento' => 'titulo-lote-1',
            'tipoEvento' => 'titulo',
        ]);
        $baixaId = $this->insertStaging('stg_conta_azul_baixas', 'baixa-lote-1', [
            'idEvento' => 'titulo-lote-1',
            'tipoEvento' => 'titulo',
            'idContaFinanceira' => 'conta-lote-1',
            'dataPagamento' => '2026-05-21',
            'valor' => '20.00',
            'metodoPagamento' => 'PIX',
        ]);
        $saldoId = $this->insertStaging('stg_conta_azul_saldos_contas_financeiras', 'conta-lote-1', [
            'saldoAtual' => '1234.56',
        ]);
        $notaId = $this->insertStaging('stg_conta_azul_notas', 'nota-lote-erro', [
            'numero' => 'NF-1',
        ]);

        $resultado = app(ContaAzulLocalCreationService::class)->criarLocalLote([
            ['entidade' => 'pessoa', 'id' => $pessoaId],
            ['entidade' => 'produto', 'id' => $produtoId],
            ['entidade' => 'conta_financeira', 'id' => $contaFinanceiraId],
            ['entidade' => 'categoria_financeira', 'id' => $categoriaId],
            ['entidade' => 'centro_custo', 'id' => $centroId],
            ['entidade' => 'forma_pagamento', 'id' => $formaId],
            ['entidade' => 'venda', 'id' => $vendaId],
            ['entidade' => 'titulo', 'id' => $tituloId],
            ['entidade' => 'conta_pagar', 'id' => $contaPagarId],
            ['entidade' => 'parcela', 'id' => $parcelaId],
            ['entidade' => 'baixa', 'id' => $baixaId],
            ['entidade' => 'saldo_conta_financeira', 'id' => $saldoId],
            ['entidade' => 'nota', 'id' => $notaId],
        ], null);

        $this->assertSame(13, $resultado['total']);
        $this->assertCount(10, $resultado['criados']);
        $this->assertCount(1, $resultado['atualizados']);
        $this->assertCount(1, $resultado['vinculados']);
        $this->assertCount(1, $resultado['erros']);
        $this->assertSame(1, $resultado['visibilidade_financeira']['contas_receber']);
        $this->assertSame(1, $resultado['visibilidade_financeira']['contas_pagar']);
        $this->assertSame(2, $resultado['visibilidade_financeira']['lancamentos_financeiros']);
        $this->assertSame(2, $resultado['visibilidade_financeira']['contas_financeiras']);
        $this->assertSame(1, $resultado['visibilidade_financeira']['categorias_financeiras']);
        $this->assertSame(1, $resultado['visibilidade_financeira']['centros_custo']);
        $this->assertSame(1, $resultado['visibilidade_financeira']['formas_pagamento']);
        $this->assertDatabaseHas('clientes', ['nome' => 'Cliente Lote']);
        $this->assertDatabaseHas('produtos', ['nome' => 'Produto Lote']);
        $this->assertDatabaseHas('pedidos', ['numero_externo' => 'VENDA-LOTE-1']);
        $this->assertDatabaseHas('contas_receber', ['descricao' => 'Titulo Lote']);
        $this->assertDatabaseHas('contas_pagar', ['descricao' => 'Conta Pagar Lote']);
        $this->assertDatabaseHas('contas_receber_pagamentos', ['valor' => '40.00']);
        $this->assertDatabaseHas('contas_receber_pagamentos', ['valor' => '20.00']);
        $this->assertDatabaseHas('lancamentos_financeiros', ['tipo' => 'receita', 'valor' => '40.00']);
        $this->assertDatabaseHas('lancamentos_financeiros', ['tipo' => 'receita', 'valor' => '20.00']);
        $this->assertDatabaseHas('contas_financeiras', ['nome' => 'Banco Lote', 'saldo_atual' => '1234.56']);
        $this->assertDatabaseHas('categorias_financeiras', ['nome' => 'Receitas Lote']);
        $this->assertDatabaseHas('centros_custo', ['nome' => 'Centro Lote']);
        $this->assertDatabaseHas('formas_pagamento', ['slug' => 'pix']);
        $this->assertDatabaseHas('stg_conta_azul_notas', [
            'id' => $notaId,
            'status_conciliacao' => 'novo',
        ]);

        $this->getJson('/api/v1/financeiro/contas-receber?busca=Titulo%20Lote')
            ->assertOk()
            ->assertJsonFragment(['descricao' => 'Titulo Lote']);
        $this->getJson('/api/v1/financeiro/contas-pagar?busca=Conta%20Pagar%20Lote')
            ->assertOk()
            ->assertJsonFragment(['descricao' => 'Conta Pagar Lote']);
        $this->getJson('/api/v1/financeiro/lancamentos?q=Conta%20a%20Receber')
            ->assertOk()
            ->assertJsonFragment(['valor' => '40.00'])
            ->assertJsonFragment(['valor' => '20.00']);
        $this->getJson('/api/v1/financeiro/contas-financeiras')
            ->assertOk()
            ->assertJsonFragment(['nome' => 'Banco Lote']);
        $this->getJson('/api/v1/financeiro/categorias-financeiras')
            ->assertOk()
            ->assertJsonFragment(['nome' => 'Receitas Lote']);
        $this->getJson('/api/v1/financeiro/centros-custo')
            ->assertOk()
            ->assertJsonFragment(['nome' => 'Centro Lote']);
        $this->getJson('/api/v1/financeiro/formas-pagamento')
            ->assertOk()
            ->assertJsonFragment(['nome' => 'PIX']);
    }

    public function test_cria_lote_por_filtro_respeitando_entidade_e_status(): void
    {
        $this->insertStaging('stg_conta_azul_pessoas', 'pessoa-filtro-1', [
            'nome' => 'Pessoa Filtro 1',
            'documento' => '11111111111',
        ]);
        $this->insertStaging('stg_conta_azul_pessoas', 'pessoa-filtro-2', [
            'nome' => 'Pessoa Filtro 2',
            'documento' => '22222222222',
        ], 'pendente');
        $this->insertStaging('stg_conta_azul_pessoas', 'pessoa-filtro-ignorada', [
            'nome' => 'Pessoa Ignorada',
        ], 'ignorado');
        $this->insertStaging('stg_conta_azul_produtos', 'produto-fora-filtro', [
            'nome' => 'Produto Fora Filtro',
        ]);

        $resultado = app(ContaAzulLocalCreationService::class)->criarLocalLotePorFiltro([
            'entidade' => 'pessoa',
            'status' => 'novo,pendente',
            'bucket' => '',
        ], null);

        $this->assertSame(2, $resultado['total']);
        $this->assertCount(2, $resultado['criados']);
        $this->assertDatabaseHas('clientes', ['nome' => 'Pessoa Filtro 1']);
        $this->assertDatabaseHas('clientes', ['nome' => 'Pessoa Filtro 2']);
        $this->assertDatabaseMissing('clientes', ['nome' => 'Pessoa Ignorada']);
        $this->assertDatabaseMissing('produtos', ['nome' => 'Produto Fora Filtro']);
    }
}
