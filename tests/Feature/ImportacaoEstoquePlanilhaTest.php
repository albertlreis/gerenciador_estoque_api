<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\EstoqueMovimentacao;
use App\Models\Fornecedor;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\ProdutoVariacaoOutletPagamento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportacaoEstoquePlanilhaTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Dev Teste',
            'email' => 'dev.importacao@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        return $usuario;
    }

    private function criarPlanilhaExemplo(): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Importacao');

        $headers = [
            'nome_produto', 'referencia', 'categoria', 'quantidade', 'status', 'outlet', 'data_entrada',
            'localizacao', 'Madeira', 'Tec. 1', 'Tec. 2', 'Metal / Vidro', 'Fornecedor',
            'preco_custo', 'preco_venda', 'comprimento', 'profundidade', 'altura', 'atributos_limpos',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $sheet->fromArray([
            'Poltrona A', 'REF-PA', 'Estofados', 10, 'Depósito JB', 0, '',
            '1-A1', 'Nogueira', 'Linho', '', 'Preto', 'Fornecedor Alpha',
            100, 160, 1.2, 0.8, 0.9, '{"acabamento":"fosco"}',
        ], null, 'A2');

        $sheet->fromArray([
            'Poltrona A', '', 'Estofados', 5, 'Em separacao', 0, '',
            '1-A2', 'Nogueira', 'Linho', '', 'Preto', 'Fornecedor Alpha',
            100, 160, 1.2, 0.8, 0.9, '',
        ], null, 'A3');

        $sheet->fromArray([
            'Mesa B', 'REF-MB', 'Mesas', 2, 'Loja', 'SIM', '2026-02-10',
            '2-B1', '', '', '', 'Metal', 'Fornecedor Beta',
            200, 300, 1.8, 0.9, 0.75, '',
        ], null, 'A4');

        $tmpPath = tempnam(sys_get_temp_dir(), 'imp-estoque-');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpPath);

        return new UploadedFile(
            $tmpPath,
            'importacao-estoque.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_importacao_estoque_bloqueia_usuario_sem_permissao_dev(): void
    {
        $this->autenticar();
        $arquivo = $this->criarPlanilhaExemplo();

        $response = $this->post('/api/v1/importacoes/estoque', [
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(403);
    }

    public function test_importacao_estoque_aplica_regras_de_status_outlet_fornecedor_e_referencia_vazia(): void
    {
        $this->autenticar();
        $arquivo = $this->criarPlanilhaExemplo();

        $headers = [
            'X-Permissoes' => json_encode(['estoque.importar_planilha_dev']),
        ];

        $upload = $this->withHeaders($headers)->post('/api/v1/importacoes/estoque', [
            'arquivo' => $arquivo,
        ]);

        $upload->assertStatus(200);
        $importId = (int) $upload->json('data.import_id');
        $this->assertGreaterThan(0, $importId);

        $preview = $this->withHeaders($headers)->postJson("/api/v1/importacoes/estoque/{$importId}/processar?dry_run=1");
        $preview->assertStatus(200);
        $this->assertSame(0, EstoqueMovimentacao::count());

        $confirm = $this->withHeaders($headers)->postJson("/api/v1/importacoes/estoque/{$importId}/processar");
        $confirm->assertStatus(200);

        $resumo = $confirm->json('resumo');
        $this->assertSame(3, (int) ($resumo['linhas_validas'] ?? 0));
        $this->assertSame(2, (int) ($resumo['movimentacoes_criadas'] ?? 0));
        $this->assertSame(1, (int) ($resumo['outlets_criados'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($resumo['fornecedores_criados'] ?? 0));

        $depositoJb = Deposito::where('nome', 'Depósito JB')->first();
        $depositoLoja = Deposito::where('nome', 'Loja')->first();
        $this->assertNotNull($depositoJb);
        $this->assertNotNull($depositoLoja);

        $this->assertDatabaseHas('estoque_movimentacoes', [
            'id_deposito_destino' => $depositoJb->id,
            'quantidade' => 10,
            'tipo' => 'entrada_deposito',
        ]);
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'id_deposito_destino' => $depositoLoja->id,
            'quantidade' => 2,
            'tipo' => 'entrada_deposito',
        ]);

        $this->assertDatabaseHas('fornecedores', ['nome' => 'Fornecedor Alpha']);
        $this->assertDatabaseHas('fornecedores', ['nome' => 'Fornecedor Beta']);

        $variacaoSemReferencia = ProdutoVariacao::where('referencia', 'like', 'SC-%')->first();
        $this->assertNotNull($variacaoSemReferencia);

        $outlet = ProdutoVariacaoOutlet::first();
        $this->assertNotNull($outlet);
        $this->assertGreaterThan(0, (int) $outlet->quantidade);

        $pagamento = ProdutoVariacaoOutletPagamento::where('produto_variacao_outlet_id', $outlet->id)->first();
        $this->assertNotNull($pagamento);
        $this->assertSame('50.00', (string) $pagamento->percentual_desconto);
    }
}
