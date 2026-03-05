<?php

namespace Tests\Feature\Estoque;

use App\Models\Deposito;
use App\Models\EstoqueTransferencia;
use App\Models\EstoqueTransferenciaItem;
use App\Models\Produto;
use App\Models\ProdutoImagem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Models\Usuario;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransferenciaPdfTest extends TestCase
{
    public function test_gera_pdf_de_transferencia_sem_excecao(): void
    {
        $transferencia = $this->buildTransferenciaFake();

        $html = view('exports.transferencia-deposito', [
            'transferencia' => $transferencia,
        ])->render();

        $this->assertStringContainsString('data:image/', $html);

        $output = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();
        $response = response($output, 200, ['Content-Type' => 'application/pdf']);

        $this->assertSame(200, $response->status());
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    private function buildTransferenciaFake(): EstoqueTransferencia
    {
        $produtoImagem = new ProdutoImagem(['url' => 'produtos/produto.png', 'principal' => true]);
        $variacaoImagem = new ProdutoVariacaoImagem(['url' => 'produtos/variacao.png']);

        $produto = new Produto(['nome' => 'Produto Teste']);
        $produto->setRelation('imagemPrincipal', $produtoImagem);

        $variacao = new ProdutoVariacao([
            'nome' => 'Variação Teste',
            'referencia' => 'REF-TESTE',
        ]);
        $variacao->setRelation('imagem', $variacaoImagem);
        $variacao->setRelation('produto', $produto);

        $item = new EstoqueTransferenciaItem([
            'quantidade' => 2,
            'corredor' => 'A',
            'prateleira' => '01',
            'nivel' => '02',
        ]);
        $item->setRelation('variacao', $variacao);
        $item->setAttribute('pdf_imagem_data_uri', 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"></svg>'));

        $transferencia = new EstoqueTransferencia([
            'uuid' => 'teste-pdf',
            'status' => 'aberta',
            'observacao' => 'Teste de geração de PDF',
            'total_itens' => 1,
            'total_pecas' => 2,
        ]);
        $transferencia->created_at = Carbon::now();
        $transferencia->setRelation('depositoOrigem', new Deposito(['nome' => 'Depósito Origem']));
        $transferencia->setRelation('depositoDestino', new Deposito(['nome' => 'Depósito Destino']));
        $transferencia->setRelation('usuario', new Usuario(['nome' => 'Usuário Teste']));
        $transferencia->setRelation('itens', collect([$item]));

        return $transferencia;
    }
}
