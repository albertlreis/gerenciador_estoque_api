<?php

namespace Tests\Unit;

use App\Models\Categoria;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\ProdutoVariacaoCodigoHistorico;
use App\Services\ImportacaoPedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportacaoPedidoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mescla_mantem_item_sem_categoria_para_selecao_manual(): void
    {
        $service = app(ImportacaoPedidoService::class);

        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-SEM-CAT-001',
                'descricao' => 'Produto sem categoria',
                'quantidade' => '1.00',
                'preco_unitario' => '10.00',
            ],
        ]);

        $this->assertCount(1, $itens);
        $this->assertNull($itens[0]['id_categoria']);
        $this->assertNull($itens[0]['categoria']);
        $this->assertDatabaseMissing('categorias', [
            'nome' => 'Importacao XML - Sem categoria',
        ]);
    }

    public function test_mescla_aplica_categoria_sugerida_em_item_sem_categoria(): void
    {
        $categoria = Categoria::create(['nome' => 'Tapete']);
        $service = app(ImportacaoPedidoService::class);

        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-TAPETE-001',
                'descricao' => 'Tapete Avanti',
                'quantidade' => '1.00',
                'preco_unitario' => '10.00',
            ],
        ], null, [
            'categoria_sugerida' => [
                'id' => $categoria->id,
                'nome' => $categoria->nome,
            ],
        ]);

        $this->assertSame($categoria->id, $itens[0]['id_categoria']);
        $this->assertSame('Tapete', $itens[0]['categoria']);
    }

    public function test_mescla_ignora_categoria_sugerida_quando_ela_nao_foi_resolvida(): void
    {
        $service = app(ImportacaoPedidoService::class);

        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-TAPETE-SEM-CADASTRO',
                'descricao' => 'Tapete Avanti sem categoria cadastrada',
                'quantidade' => '1.00',
                'preco_unitario' => '10.00',
            ],
        ], null, [
            'categoria_sugerida' => null,
        ]);

        $this->assertNull($itens[0]['id_categoria']);
        $this->assertNull($itens[0]['categoria']);
        $this->assertDatabaseMissing('categorias', [
            'nome' => 'Tapete',
        ]);
    }

    public function test_mescla_nao_sobrescreve_categoria_de_variacao_com_categoria_sugerida(): void
    {
        $categoriaReal = Categoria::create(['nome' => 'Categoria Real']);
        $categoriaSugerida = Categoria::create(['nome' => 'Tapete']);
        $produto = Produto::create([
            'nome' => 'Produto Existente',
            'id_categoria' => $categoriaReal->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-EXISTENTE',
            'nome' => 'Variacao Existente',
            'preco' => 10,
            'custo' => 5,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-EXISTENTE',
                'descricao' => 'Produto XML existente',
                'quantidade' => '1',
                'preco_unitario' => '99',
            ],
        ], null, [
            'categoria_sugerida' => [
                'id' => $categoriaSugerida->id,
                'nome' => $categoriaSugerida->nome,
            ],
        ]);

        $this->assertSame($variacao->id, $itens[0]['id_variacao']);
        $this->assertSame($categoriaReal->id, $itens[0]['id_categoria']);
        $this->assertSame('Categoria Real', $itens[0]['categoria']);
    }

    public function test_mescla_encontra_variacao_por_codigo_barras(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $produto = Produto::create([
            'nome' => 'Produto Codigo de Barras',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'nome' => 'Variacao CB',
            'referencia' => 'REF-CB-001',
            'codigo_barras' => '7891234567890',
            'preco' => 15.5,
            'custo' => 8.2,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo_barras' => '7891234567890',
                'nome' => 'Item por codigo de barras',
                'quantidade' => '2',
                'preco_unitario' => '10',
            ],
        ]);

        $this->assertCount(1, $itens);
        $this->assertSame($variacao->id, $itens[0]['id_variacao']);
        $this->assertSame($produto->id, $itens[0]['produto_id']);
        $this->assertSame($categoria->id, $itens[0]['id_categoria']);
    }

    public function test_mescla_referencia_unica_vincula_automaticamente_e_mantem_lista_de_preview(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria Unica']);
        $produto = Produto::create([
            'nome' => 'Produto Unico',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-UNICA',
            'nome' => 'Variacao Unica',
            'preco' => 10,
            'custo' => 5,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-UNICA',
                'descricao' => 'Produto XML',
                'quantidade' => '1',
                'preco_unitario' => '99',
                'atributos_detectados' => [],
            ],
        ]);

        $this->assertSame($variacao->id, $itens[0]['id_variacao']);
        $this->assertCount(1, $itens[0]['variacoes_encontradas']);
    }

    public function test_mescla_referencia_ambigua_exige_selecao_manual_no_preview(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria Ambigua']);
        $produto = Produto::create([
            'nome' => 'Produto Ambiguo',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-AMBIGUA',
            'nome' => 'Variacao A',
            'preco' => 10,
            'custo' => 5,
        ]);
        ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-AMBIGUA',
            'nome' => 'Variacao B',
            'preco' => 12,
            'custo' => 6,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-AMBIGUA',
                'descricao' => 'Produto XML ambiguo',
                'quantidade' => '1',
                'preco_unitario' => '99',
            ],
        ]);

        $this->assertNull($itens[0]['id_variacao']);
        $this->assertCount(2, $itens[0]['variacoes_encontradas']);
    }

    public function test_identificacao_automatica_seleciona_melhor_variacao_sem_conflitos(): void
    {
        $categoria = Categoria::create(['nome' => 'Poltrona']);
        $produto = Produto::create([
            'nome' => 'Poltrona Nidus',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $compativel = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '5330',
            'nome' => 'Nidus AC03 I-24805',
            'preco' => 100,
            'custo' => 50,
        ]);
        $conflitante = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '5330',
            'nome' => 'Nidus AC15 I-24805',
            'preco' => 100,
            'custo' => 50,
        ]);

        foreach ([
            [$compativel, 'madeira', 'AC03'],
            [$compativel, 'tecido_1', 'I-24805'],
            [$conflitante, 'madeira', 'AC15'],
            [$conflitante, 'tecido_1', 'I-24805'],
        ] as [$variacao, $atributo, $valor]) {
            ProdutoVariacaoAtributo::create([
                'id_variacao' => $variacao->id,
                'atributo' => $atributo,
                'valor' => $valor,
            ]);
        }

        $itens = app(ImportacaoPedidoService::class)->mesclarItensComVariacoes([[
            'codigo' => '5330',
            'ref' => '5330',
            'codigo_origem' => '5330[244271425]',
            'nome' => 'POLTRONA NIDUS',
            'descricao' => 'POLTRONA NIDUS CORAC03 TECI-24805 PESPMESMA COR DO TECIDO',
            'atributos' => [
                'madeira' => 'ac-03',
                'tecido_1' => 'I 24805',
                'pes' => 'Mesma cor do tecido',
            ],
            'atributos_detectados' => [
                'madeira' => 'ac-03',
                'tecido_1' => 'I 24805',
                'pes' => 'Mesma cor do tecido',
            ],
        ]]);

        $this->assertSame($compativel->id, $itens[0]['id_variacao']);
        $this->assertFalse($itens[0]['forcar_produto_novo']);
        $this->assertSame('existente', $itens[0]['vinculo_sugerido']['decisao']);
        $this->assertSame(2, $itens[0]['vinculo_sugerido']['compativeis']);
        $this->assertSame(2, $itens[0]['vinculo_sugerido']['total']);
        $this->assertSame('Mesma cor do tecido', $itens[0]['atributos']['pes']);
        $this->assertSame('ac-03', $itens[0]['atributos_detectados']['madeira']);
        $this->assertSame('POLTRONA NIDUS', $itens[0]['nome_detectado']);
        $this->assertSame(
            'POLTRONA NIDUS CORAC03 TECI-24805 PESPMESMA COR DO TECIDO',
            $itens[0]['descricao']
        );
        $this->assertSame($compativel->id, $itens[0]['variacoes_encontradas'][0]['id_variacao']);
        $this->assertSame(
            ['madeira', 'tecido_1'],
            $itens[0]['variacoes_encontradas'][0]['compatibilidade']['compativeis']
        );
        $this->assertSame(
            ['madeira'],
            $itens[0]['variacoes_encontradas'][1]['compatibilidade']['conflitos']
        );
    }

    public function test_identificacao_automatica_prioriza_codigo_origem_exato_sem_conflito(): void
    {
        $categoria = Categoria::create(['nome' => 'Poltrona Historico']);
        $produto = Produto::create([
            'nome' => 'Poltrona Historico',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $historica = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '5330',
            'nome' => 'Historica',
            'preco' => 100,
            'custo' => 50,
        ]);
        $maisCompleta = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '5330',
            'nome' => 'Mais completa',
            'preco' => 100,
            'custo' => 50,
        ]);

        ProdutoVariacaoAtributo::create([
            'id_variacao' => $historica->id,
            'atributo' => 'madeira',
            'valor' => 'AC03',
        ]);
        foreach ([['madeira', 'AC03'], ['tecido_1', 'I-24805']] as [$atributo, $valor]) {
            ProdutoVariacaoAtributo::create([
                'id_variacao' => $maisCompleta->id,
                'atributo' => $atributo,
                'valor' => $valor,
            ]);
        }
        ProdutoVariacaoCodigoHistorico::create([
            'produto_variacao_id' => $historica->id,
            'codigo' => '5330[244271425]',
            'codigo_origem' => '5330[244271425]',
            'hash_conteudo' => sha1('historico-nidus'),
            'fonte' => 'importacao_pedido_xml',
        ]);

        $itens = app(ImportacaoPedidoService::class)->mesclarItensComVariacoes([[
            'codigo' => '5330',
            'codigo_origem' => '5330[244271425]',
            'nome' => 'POLTRONA NIDUS',
            'atributos' => ['madeira' => 'AC03', 'tecido_1' => 'I-24805'],
            'atributos_detectados' => ['madeira' => 'AC03', 'tecido_1' => 'I-24805'],
        ]]);

        $this->assertSame($historica->id, $itens[0]['id_variacao']);
        $this->assertSame('codigo_origem_exato', $itens[0]['vinculo_sugerido']['motivo']);
        $this->assertTrue($itens[0]['variacoes_encontradas'][0]['compatibilidade']['codigo_origem_exato']);
    }

    public function test_identificacao_automatica_compara_conjunto_completo_de_atributo_repetido(): void
    {
        $categoria = Categoria::create(['nome' => 'Poltrona Atributos Repetidos']);
        $produto = Produto::create([
            'nome' => 'Poltrona Repetida',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $conjuntoCompleto = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REP-01',
            'nome' => 'Conjunto completo',
            'preco' => 100,
            'custo' => 50,
        ]);
        $historicaIncompleta = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REP-01',
            'nome' => 'Historica incompleta',
            'preco' => 100,
            'custo' => 50,
        ]);

        foreach (['AC03', 'MT31-PRETO'] as $valor) {
            ProdutoVariacaoAtributo::create([
                'id_variacao' => $conjuntoCompleto->id,
                'atributo' => 'madeira',
                'valor' => $valor,
            ]);
        }
        ProdutoVariacaoAtributo::create([
            'id_variacao' => $historicaIncompleta->id,
            'atributo' => 'madeira',
            'valor' => 'AC03',
        ]);
        ProdutoVariacaoCodigoHistorico::create([
            'produto_variacao_id' => $historicaIncompleta->id,
            'codigo' => 'REP-01[ORIGEM]',
            'codigo_origem' => 'REP-01[ORIGEM]',
            'hash_conteudo' => sha1('rep-01-origem'),
            'fonte' => 'importacao_pedido_xml',
        ]);

        $itens = app(ImportacaoPedidoService::class)->mesclarItensComVariacoes([[
            'codigo' => 'REP-01',
            'codigo_origem' => 'REP-01[ORIGEM]',
            'nome' => 'Poltrona Repetida',
            'atributos' => ['madeira' => 'MT31-PRETO'],
            'atributos_lista' => [
                ['atributo' => 'madeira', 'valor' => 'AC03'],
                ['atributo' => 'madeira', 'valor' => 'MT31 PRETO'],
            ],
            'atributos_detectados' => ['madeira' => 'MT31-PRETO'],
            'atributos_detectados_lista' => [
                ['atributo' => 'madeira', 'valor' => 'AC03'],
                ['atributo' => 'madeira', 'valor' => 'MT31 PRETO'],
            ],
        ]]);

        $this->assertSame($conjuntoCompleto->id, $itens[0]['id_variacao']);
        $this->assertSame('atributos_compativeis', $itens[0]['vinculo_sugerido']['motivo']);
        $this->assertSame([
            ['atributo' => 'madeira', 'valor' => 'AC03'],
            ['atributo' => 'madeira', 'valor' => 'MT31-PRETO'],
        ], $itens[0]['atributos_lista']);
        $this->assertSame([
            ['atributo' => 'madeira', 'valor' => 'AC03'],
            ['atributo' => 'madeira', 'valor' => 'MT31 PRETO'],
        ], $itens[0]['atributos_detectados_lista']);

        $historicaPreview = collect($itens[0]['variacoes_encontradas'])
            ->firstWhere('id_variacao', $historicaIncompleta->id);
        $this->assertTrue($historicaPreview['compatibilidade']['codigo_origem_exato']);
        $this->assertSame(['madeira'], $historicaPreview['compatibilidade']['conflitos']);
        $this->assertFalse($historicaPreview['compatibilidade']['elegivel']);
    }

    public function test_identificacao_automatica_encaminha_conflito_de_conjunto_repetido_para_revisao(): void
    {
        $categoria = Categoria::create(['nome' => 'Poltrona Conjunto Incompleto']);
        $produto = Produto::create([
            'nome' => 'Poltrona Conjunto Incompleto',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REP-REVISAO',
            'nome' => 'Somente uma madeira',
            'preco' => 100,
            'custo' => 50,
        ]);
        ProdutoVariacaoAtributo::create([
            'id_variacao' => $variacao->id,
            'atributo' => 'madeira',
            'valor' => 'AC03',
        ]);
        ProdutoVariacaoCodigoHistorico::create([
            'produto_variacao_id' => $variacao->id,
            'codigo' => 'REP-REVISAO[ORIGEM]',
            'codigo_origem' => 'REP-REVISAO[ORIGEM]',
            'hash_conteudo' => sha1('rep-revisao-origem'),
            'fonte' => 'importacao_pedido_xml',
        ]);

        $itens = app(ImportacaoPedidoService::class)->mesclarItensComVariacoes([[
            'codigo' => 'REP-REVISAO',
            'codigo_origem' => 'REP-REVISAO[ORIGEM]',
            'nome' => 'Poltrona Conjunto Incompleto',
            'atributos_lista' => [
                ['atributo' => 'madeira', 'valor' => 'AC03'],
                ['atributo' => 'madeira', 'valor' => 'MT31-PRETO'],
            ],
            'atributos_detectados_lista' => [
                ['atributo' => 'madeira', 'valor' => 'AC03'],
                ['atributo' => 'madeira', 'valor' => 'MT31-PRETO'],
            ],
        ]]);

        $this->assertNull($itens[0]['id_variacao']);
        $this->assertFalse($itens[0]['forcar_produto_novo']);
        $this->assertSame('revisao', $itens[0]['vinculo_sugerido']['decisao']);
        $this->assertSame('conflito_conjunto_repetido', $itens[0]['vinculo_sugerido']['motivo']);
        $this->assertSame(
            ['madeira'],
            $itens[0]['variacoes_encontradas'][0]['compatibilidade']['conflitos_conjunto_repetido']
        );
        $this->assertTrue($itens[0]['variacoes_encontradas'][0]['compatibilidade']['codigo_origem_exato']);
        $this->assertFalse($itens[0]['variacoes_encontradas'][0]['compatibilidade']['elegivel']);
    }

    public function test_identificacao_automatica_deixa_empate_para_revisao(): void
    {
        $categoria = Categoria::create(['nome' => 'Mesa Empate']);
        $produto = Produto::create([
            'nome' => 'Mesa Empate',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $porMadeira = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'EMP-01',
            'nome' => 'Por madeira',
            'preco' => 100,
            'custo' => 50,
        ]);
        $porTecido = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'EMP-01',
            'nome' => 'Por tecido',
            'preco' => 100,
            'custo' => 50,
        ]);
        ProdutoVariacaoAtributo::create([
            'id_variacao' => $porMadeira->id,
            'atributo' => 'madeira',
            'valor' => 'AC03',
        ]);
        ProdutoVariacaoAtributo::create([
            'id_variacao' => $porTecido->id,
            'atributo' => 'tecido_1',
            'valor' => 'I-24805',
        ]);

        $itens = app(ImportacaoPedidoService::class)->mesclarItensComVariacoes([[
            'codigo' => 'EMP-01',
            'nome' => 'Mesa Empate',
            'atributos' => ['madeira' => 'AC03', 'tecido_1' => 'I-24805'],
            'atributos_detectados' => ['madeira' => 'AC03', 'tecido_1' => 'I-24805'],
        ]]);

        $this->assertNull($itens[0]['id_variacao']);
        $this->assertFalse($itens[0]['forcar_produto_novo']);
        $this->assertSame('revisao', $itens[0]['vinculo_sugerido']['decisao']);
        $this->assertSame('empate_compatibilidade', $itens[0]['vinculo_sugerido']['motivo']);
    }

    public function test_identificacao_automatica_seleciona_nova_quando_nao_ha_compatibilidade(): void
    {
        $categoria = Categoria::create(['nome' => 'Mesa Nova']);
        $produto = Produto::create([
            'nome' => 'Mesa Nova',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'NOVA-01',
            'nome' => 'Existente incompatível',
            'preco' => 100,
            'custo' => 50,
        ]);
        $apenasMesmoSku = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'OUTRA-REF',
            'sku_interno' => 'NOVA-01',
            'nome' => 'Mesmo SKU fora da referência base',
            'preco' => 100,
            'custo' => 50,
        ]);
        ProdutoVariacaoAtributo::create([
            'id_variacao' => $variacao->id,
            'atributo' => 'madeira',
            'valor' => 'AC15',
        ]);
        ProdutoVariacaoAtributo::create([
            'id_variacao' => $apenasMesmoSku->id,
            'atributo' => 'madeira',
            'valor' => 'AC03',
        ]);

        $itens = app(ImportacaoPedidoService::class)->mesclarItensComVariacoes([[
            'codigo' => 'NOVA-01',
            'nome' => 'Mesa Nova',
            'atributos' => ['madeira' => 'AC03'],
            'atributos_detectados' => ['madeira' => 'AC03'],
        ]]);

        $this->assertNull($itens[0]['id_variacao']);
        $this->assertTrue($itens[0]['forcar_produto_novo']);
        $this->assertSame('nova', $itens[0]['vinculo_sugerido']['decisao']);
        $this->assertCount(1, $itens[0]['variacoes_encontradas']);
    }

    public function test_identificacao_automatica_ignora_pes_no_matching_e_compara_atributo_legado(): void
    {
        $categoria = Categoria::create(['nome' => 'Poltrona Legada']);
        $produto = Produto::create([
            'nome' => 'Poltrona Legada',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'LEG-01',
            'nome' => 'Legada',
            'preco' => 100,
            'custo' => 50,
        ]);
        ProdutoVariacaoAtributo::create([
            'id_variacao' => $variacao->id,
            'atributo' => 'modelo_referencia',
            'valor' => 'COR: AC03 TEC: I-24805',
        ]);

        $service = app(ImportacaoPedidoService::class);
        $legado = $service->mesclarItensComVariacoes([[
            'codigo' => 'LEG-01',
            'nome' => 'Poltrona Legada',
            'atributos' => ['madeira' => 'AC03', 'tecido_1' => 'I-24805', 'pes' => 'Mesma cor'],
            'atributos_detectados' => ['madeira' => 'AC03', 'tecido_1' => 'I-24805', 'pes' => 'Mesma cor'],
        ]]);
        $somentePes = $service->mesclarItensComVariacoes([[
            'codigo' => 'LEG-01',
            'nome' => 'Poltrona Legada',
            'atributos' => ['pes' => 'Mesma cor'],
            'atributos_detectados' => ['pes' => 'Mesma cor'],
        ]]);

        $this->assertSame($variacao->id, $legado[0]['id_variacao']);
        $this->assertSame(2, $legado[0]['vinculo_sugerido']['total']);
        $this->assertNull($somentePes[0]['id_variacao']);
        $this->assertTrue($somentePes[0]['forcar_produto_novo']);
        $this->assertSame(0, $somentePes[0]['vinculo_sugerido']['total']);
    }

    public function test_mescla_sku_interno_repetido_retorna_pendencia_de_variacao(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria SKU Duplicado']);

        $produtoAntigo = Produto::create([
            'nome' => 'Produto Antigo',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacaoAntiga = ProdutoVariacao::create([
            'produto_id' => $produtoAntigo->id,
            'referencia' => 'REF-SKU-PEDIDO-OLD',
            'sku_interno' => 'SKU-PEDIDO-DUP',
            'nome' => 'Variacao antiga',
            'preco' => 10,
            'custo' => 5,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $produtoRecente = Produto::create([
            'nome' => 'Produto Recente',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacaoRecente = ProdutoVariacao::create([
            'produto_id' => $produtoRecente->id,
            'referencia' => 'REF-SKU-PEDIDO-NEW',
            'sku_interno' => 'SKU-PEDIDO-DUP',
            'nome' => 'Variacao recente',
            'preco' => 12,
            'custo' => 6,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([[
            'codigo' => 'SKU-PEDIDO-DUP',
            'descricao' => 'Produto XML SKU duplicado',
            'quantidade' => '1',
            'preco_unitario' => '99',
        ]]);

        $this->assertNull($itens[0]['id_variacao']);
        $this->assertNull($itens[0]['produto_id']);
        $this->assertCount(2, $itens[0]['variacoes_encontradas']);
        $this->assertEqualsCanonicalizing(
            [$variacaoAntiga->id, $variacaoRecente->id],
            array_column($itens[0]['variacoes_encontradas'], 'id_variacao')
        );
    }
}
