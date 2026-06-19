<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Fornecedor;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoPedidoNfeXmlTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(string $name): string
    {
        return base_path("tests/Fixtures/{$name}");
    }

    public function test_importa_nfe_xml_retorna_200_e_itens_nao_vazio(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe',
            'email' => 'nfe@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $fornecedor = Fornecedor::create([
            'nome' => 'Queen Books',
            'cnpj' => '07.266.606/0001-12',
            'status' => 1,
        ]);

        $path = $this->fixturePath('nfe-35250207.xml');
        $this->assertFileExists($path);
        $file = new UploadedFile($path, 'nfe-35250207.xml', 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'ADORNOS_XML_NFE',
                'arquivo' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.pedido.numero_externo', '');
        $response->assertJsonPath('dados.pedido.id_fornecedor', $fornecedor->id);
        $response->assertJsonPath('dados.pedido.fornecedor_sugerido.cnpj', '07266606000112');
        $response->assertJsonPath('dados.itens_extraidos', true);
        $response->assertJsonPath('dados.requer_insercao_manual', false);
        $this->assertGreaterThan(0, count($response->json('dados.itens') ?? []));
    }

    public function test_importa_segundo_nfe_xml_retorna_200_e_itens_nao_vazio(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe 2',
            'email' => 'nfe2@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->fixturePath('nfe-35260201.xml');
        $this->assertFileExists($path);
        $file = new UploadedFile($path, 'nfe-35260201.xml', 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'ADORNOS_XML_NFE',
                'arquivo' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $this->assertGreaterThan(0, count($response->json('dados.itens') ?? []));
    }

    public function test_importa_nfe_com_metragem_como_linha_unica_preservando_dados_fiscais(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe Metragem',
            'email' => 'nfe-metragem@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->tempXmlPath($this->xmlNfeMetragem());
        $file = new UploadedFile($path, '10665-NFe AVANTI.xml', 'application/xml', null, true);

        try {
            $response = $this->actingAs($usuario, 'sanctum')
                ->post('/api/v1/pedidos/import', [
                    'tipo_importacao' => 'ADORNOS_XML_NFE',
                    'arquivo' => $file,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.pedido.id_fornecedor', null);
        $response->assertJsonPath('dados.pedido.total', 26474.85);
        $response->assertJsonPath('dados.itens.0.quantidade', '1');
        $response->assertJsonPath('dados.itens.0.preco_unitario', '3106.35');
        $response->assertJsonPath('dados.itens.0.atributos.linha', 'SMAD');
        $response->assertJsonPath('dados.itens.0.atributos.modelo_referencia', 'IKAT 2');
        $response->assertJsonPath('dados.itens.0.atributos.cor', 'BLUE');
        $response->assertJsonPath('dados.itens.0.atributos.gramatura', '1200G');
        $response->assertJsonPath('dados.itens.0.atributos.espessura', '10MM');
        $response->assertJsonPath('dados.itens.0.atributos_nfe.unidade_nfe', 'M2');
        $response->assertJsonPath('dados.itens.0.atributos_nfe.quantidade_nfe', '7.5000');
        $response->assertJsonPath('dados.itens.0.atributos_nfe.valor_unitario_nfe', '414.180000');

        $itens = $response->json('dados.itens') ?? [];
        $this->assertCount(4, $itens);
        $this->assertSame(['1', '1', '1', '1'], array_column($itens, 'quantidade'));
        $this->assertSame([null, null, null, null], array_column($itens, 'id_categoria'));
        $this->assertArrayNotHasKey('unidade_nfe', $itens[0]['atributos']);
        $this->assertArrayNotHasKey('quantidade_nfe', $itens[0]['atributos']);
        $this->assertArrayNotHasKey('valor_unitario_nfe', $itens[0]['atributos']);
        $this->assertArrayNotHasKey('observacao', $itens[0]['atributos']);
        $this->assertDatabaseMissing('categorias', [
            'nome' => 'Tapete',
        ]);
        $this->assertDatabaseMissing('fornecedores', [
            'nome' => 'Avanti',
        ]);
    }

    public function test_importa_nfe_avanti_sugere_categoria_tapete_quando_categoria_existir(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe Avanti',
            'email' => 'nfe-avanti@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $categoria = Categoria::create(['nome' => 'Tapete']);
        $fornecedor = Fornecedor::create([
            'nome' => 'Avanti',
            'cnpj' => null,
            'status' => 1,
        ]);

        $path = $this->tempXmlPath($this->xmlNfeMetragem());
        $file = new UploadedFile($path, '10665-NFe AVANTI.xml', 'application/xml', null, true);

        try {
            $response = $this->actingAs($usuario, 'sanctum')
                ->post('/api/v1/pedidos/import', [
                    'tipo_importacao' => 'ADORNOS_XML_NFE',
                    'arquivo' => $file,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.pedido.id_fornecedor', $fornecedor->id);
        $response->assertJsonPath('dados.itens.0.id_categoria', $categoria->id);
        $response->assertJsonPath('dados.itens.0.categoria', 'Tapete');
        $response->assertJsonPath('dados.itens.0.atributos.linha', 'SMAD');
        $response->assertJsonPath('dados.itens.0.atributos.modelo_referencia', 'IKAT 2');
        $response->assertJsonPath('dados.itens.0.atributos.cor', 'BLUE');
        $response->assertJsonPath('dados.itens.0.atributos.gramatura', '1200G');
        $response->assertJsonPath('dados.itens.0.atributos.espessura', '10MM');
        $response->assertJsonPath('dados.itens.1.atributos.linha', 'SE2B');
        $response->assertJsonPath('dados.itens.1.atributos.modelo_referencia', 'GEOMETRIA DO TEMPO 4');
        $response->assertJsonPath('dados.itens.3.atributos.linha', 'SMAD');
        $response->assertJsonPath('dados.itens.3.atributos.modelo_referencia', 'DUNE PERSONALIZADO');
        $response->assertJsonPath('dados.itens.3.atributos.gramatura', '1200G');
        $response->assertJsonPath('dados.itens.3.atributos.espessura', '10MM');

        $itens = $response->json('dados.itens') ?? [];
        $this->assertCount(4, $itens);
        $this->assertSame(
            [$categoria->id, $categoria->id, $categoria->id, $categoria->id],
            array_column($itens, 'id_categoria')
        );
        $this->assertSame(['Tapete', 'Tapete', 'Tapete', 'Tapete'], array_column($itens, 'categoria'));
    }

    public function test_importa_nfe_snl_sem_avanti_no_nome_do_arquivo_sugere_tapete_e_fornecedor_avanti(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe SNL Avanti',
            'email' => 'nfe-snl-avanti@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $categoria = Categoria::create(['nome' => 'Tapete']);
        $fornecedor = Fornecedor::create([
            'nome' => 'Avanti',
            'cnpj' => null,
            'status' => 1,
        ]);

        $path = $this->tempXmlPath($this->xmlNfeMetragem());
        $file = new UploadedFile($path, '10665-NFe.xml', 'application/xml', null, true);

        try {
            $response = $this->actingAs($usuario, 'sanctum')
                ->post('/api/v1/pedidos/import', [
                    'tipo_importacao' => 'ADORNOS_XML_NFE',
                    'arquivo' => $file,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.pedido.id_fornecedor', $fornecedor->id);

        $itens = $response->json('dados.itens') ?? [];
        $this->assertCount(4, $itens);
        $this->assertSame(
            [$categoria->id, $categoria->id, $categoria->id, $categoria->id],
            array_column($itens, 'id_categoria')
        );
        $this->assertSame(['Tapete', 'Tapete', 'Tapete', 'Tapete'], array_column($itens, 'categoria'));
    }

    public function test_importa_nfe_avanti_prioriza_fornecedor_avanti_mesmo_com_emitente_cadastrado(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe Avanti Prioridade',
            'email' => 'nfe-avanti-prioridade@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $emitente = Fornecedor::create([
            'nome' => 'SNL INDUSTRIA E COMERCIO TEXTIL LTDA',
            'cnpj' => '09341891000467',
            'status' => 1,
        ]);
        $avanti = Fornecedor::create([
            'nome' => 'Avanti',
            'cnpj' => null,
            'status' => 1,
        ]);

        $path = $this->tempXmlPath($this->xmlNfeMetragem());
        $file = new UploadedFile($path, '10665-NFe.xml', 'application/xml', null, true);

        try {
            $response = $this->actingAs($usuario, 'sanctum')
                ->post('/api/v1/pedidos/import', [
                    'tipo_importacao' => 'ADORNOS_XML_NFE',
                    'arquivo' => $file,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.pedido.id_fornecedor', $avanti->id);
        $this->assertNotSame($emitente->id, $response->json('dados.pedido.id_fornecedor'));
    }

    public function test_importa_nfe_avanti_nao_seleciona_fornecedor_quando_match_for_ambiguo(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe Avanti Ambiguo',
            'email' => 'nfe-avanti-ambiguo@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        Fornecedor::create([
            'nome' => 'Avanti Tapetes',
            'cnpj' => null,
            'status' => 1,
        ]);
        Fornecedor::create([
            'nome' => 'Avanti Decor',
            'cnpj' => null,
            'status' => 1,
        ]);

        $path = $this->tempXmlPath($this->xmlNfeMetragem());
        $file = new UploadedFile($path, '10665-NFe AVANTI.xml', 'application/xml', null, true);

        try {
            $response = $this->actingAs($usuario, 'sanctum')
                ->post('/api/v1/pedidos/import', [
                    'tipo_importacao' => 'ADORNOS_XML_NFE',
                    'arquivo' => $file,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.pedido.id_fornecedor', null);
    }

    public function test_detecta_nfe_mesmo_quando_tipo_enviado_for_xml_fornecedor(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe Tipo Errado',
            'email' => 'nfe-tipo-errado@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->tempXmlPath($this->xmlNfeMetragem());
        $file = new UploadedFile($path, '10665-NFe AVANTI.xml', 'application/xml', null, true);

        try {
            $response = $this->actingAs($usuario, 'sanctum')
                ->post('/api/v1/pedidos/import', [
                    'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                    'arquivo' => $file,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.tipo_importacao', 'ADORNOS_XML_NFE');
        $response->assertJsonPath('dados.pedido.total', 26474.85);
        $response->assertJsonPath('dados.itens.0.quantidade', '1');
        $response->assertJsonPath('dados.itens.0.preco_unitario', '3106.35');
        $response->assertJsonPath('dados.itens.0.atributos.linha', 'SMAD');
        $response->assertJsonPath('dados.itens.0.atributos.modelo_referencia', 'IKAT 2');
        $response->assertJsonPath('dados.itens.0.atributos_nfe.unidade_nfe', 'M2');
        $response->assertJsonPath('dados.itens.0.atributos_nfe.quantidade_nfe', '7.5000');
        $response->assertJsonPath('dados.itens.0.atributos_nfe.valor_unitario_nfe', '414.180000');
        $this->assertCount(4, $response->json('dados.itens') ?? []);
    }

    public function test_rejeita_arquivo_zone_identifier_com_422(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe',
            'email' => 'nfe-rej@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->fixturePath('nfe-35250207.xml');
        $file = new UploadedFile($path, 'arquivo.xml:Zone.Identifier', 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'ADORNOS_XML_NFE',
                'arquivo' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('mensagem', 'Arquivo de metadados (Zone.Identifier) não é aceito.');
    }

    public function test_reimportacao_de_nfe_cria_novo_preview_com_sucesso(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe Reimportacao',
            'email' => 'nfe-reimport@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->fixturePath('nfe-35250207.xml');
        $file1 = new UploadedFile($path, 'nfe-35250207.xml', 'application/xml', null, true);
        $file2 = new UploadedFile($path, 'nfe-35250207.xml', 'application/xml', null, true);

        $first = $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
            'tipo_importacao' => 'ADORNOS_XML_NFE',
            'arquivo' => $file1,
        ]);

        $second = $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
            'tipo_importacao' => 'ADORNOS_XML_NFE',
            'arquivo' => $file2,
        ]);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertNotSame($first->json('importacao_id'), $second->json('importacao_id'));
    }

    private function tempXmlPath(string $content): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nfe-metragem-' . uniqid('', true) . '.xml';
        file_put_contents($path, $content);

        return $path;
    }

    private function xmlNfeMetragem(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<nfeProc versao="4.00" xmlns="http://www.portalfiscal.inf.br/nfe">
  <NFe>
    <infNFe versao="4.00" Id="NFe33260409341891000467550010000106651001066519">
      <ide>
        <cUF>33</cUF>
        <cNF>00106651</cNF>
        <natOp>VENDA</natOp>
        <mod>55</mod>
        <serie>1</serie>
        <nNF>10665</nNF>
        <dhEmi>2026-04-17T17:54:37-03:00</dhEmi>
        <tpNF>1</tpNF>
        <idDest>2</idDest>
        <cMunFG>3304557</cMunFG>
        <tpImp>1</tpImp>
        <tpEmis>1</tpEmis>
        <cDV>9</cDV>
        <tpAmb>1</tpAmb>
        <finNFe>1</finNFe>
        <indFinal>0</indFinal>
        <indPres>9</indPres>
        <procEmi>0</procEmi>
        <verProc>1.8.0</verProc>
      </ide>
      <emit>
        <CNPJ>09341891000467</CNPJ>
        <xNome>SNL INDUSTRIA E COMERCIO TEXTIL LTDA</xNome>
        <enderEmit>
          <xLgr>AV AYRTON SENNA</xLgr>
          <nro>2150</nro>
          <xBairro>Barra Da Tijuca</xBairro>
          <cMun>3304557</cMun>
          <xMun>RIO DE JANEIRO</xMun>
          <UF>RJ</UF>
          <CEP>22775003</CEP>
          <cPais>1058</cPais>
          <xPais>Brasil</xPais>
        </enderEmit>
      </emit>
      <dest>
        <CNPJ>10503517000157</CNPJ>
        <xNome>R E C COMERCIO VAREJISTA DE ELET. MODUL.</xNome>
      </dest>
      <det nItem="1">
        <prod>
          <cProd>11.10443-3</cProd>
          <cEAN>SEM GTIN</cEAN>
          <xProd>SMAD IKAT 2 BLUE 1200G 10MM</xProd>
          <NCM>58023000</NCM>
          <CFOP>6102</CFOP>
          <uCom>M2</uCom>
          <qCom>7.5000</qCom>
          <vUnCom>414.180000</vUnCom>
          <vProd>3106.35</vProd>
          <cEANTrib>SEM GTIN</cEANTrib>
          <uTrib>M2</uTrib>
          <qTrib>7.5000</qTrib>
          <vUnTrib>414.180000</vUnTrib>
          <indTot>1</indTot>
        </prod>
      </det>
      <det nItem="2">
        <prod>
          <cProd>11.10445-5</cProd>
          <cEAN>SEM GTIN</cEAN>
          <xProd>SE2B GEOMETRIA DO TEMPO 4</xProd>
          <NCM>58023000</NCM>
          <CFOP>6102</CFOP>
          <uCom>M2</uCom>
          <qCom>15.0000</qCom>
          <vUnCom>480.600000</vUnCom>
          <vProd>7209.00</vProd>
          <cEANTrib>SEM GTIN</cEANTrib>
          <uTrib>M2</uTrib>
          <qTrib>15.0000</qTrib>
          <vUnTrib>480.600000</vUnTrib>
          <indTot>1</indTot>
        </prod>
      </det>
      <det nItem="3">
        <prod>
          <cProd>11.10054-4</cProd>
          <cEAN>SEM GTIN</cEAN>
          <xProd>SE2B GATSBY</xProd>
          <NCM>58023000</NCM>
          <CFOP>6102</CFOP>
          <uCom>M2</uCom>
          <qCom>24.0000</qCom>
          <vUnCom>540.000000</vUnCom>
          <vProd>12960.00</vProd>
          <cEANTrib>SEM GTIN</cEANTrib>
          <uTrib>M2</uTrib>
          <qTrib>24.0000</qTrib>
          <vUnTrib>540.000000</vUnTrib>
          <indTot>1</indTot>
        </prod>
      </det>
      <det nItem="4">
        <prod>
          <cProd>11.10493-3</cProd>
          <cEAN>SEM GTIN</cEAN>
          <xProd>SMAD DUNE PERSONALIZADO 1200G 10MM</xProd>
          <NCM>58023000</NCM>
          <CFOP>6102</CFOP>
          <uCom>M2</uCom>
          <qCom>7.5000</qCom>
          <vUnCom>426.600000</vUnCom>
          <vProd>3199.50</vProd>
          <cEANTrib>SEM GTIN</cEANTrib>
          <uTrib>M2</uTrib>
          <qTrib>7.5000</qTrib>
          <vUnTrib>426.600000</vUnTrib>
          <indTot>1</indTot>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vBC>26474.85</vBC>
          <vICMS>1853.24</vICMS>
          <vProd>26474.85</vProd>
          <vFrete>0.00</vFrete>
          <vSeg>0.00</vSeg>
          <vDesc>0.00</vDesc>
          <vNF>26474.85</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }
}
