<?php

namespace App\Domain\Importacao\Services;

use App\Domain\Importacao\DTO\NotaDTO;
use App\Domain\Importacao\DTO\ProdutoImportadoDTO;
use App\Domain\Importacao\DTO\AtributoDTO;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\EstoqueMovimentacao;
use App\Support\RefHelpers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ImportacaoProdutosService
{
    /** Normaliza nome de atributo (ex: “COR”, “ Cor ” → “cor”) */
    private static function normalizarAtributo(string $valor): string
    {
        return (string) Str::of($valor)->squish()->lower();
    }

    /** Normaliza valor (mantém caixa amigável, remove espaços extras) */
    private static function normalizarValor(string $valor): string
    {
        $val = (string) Str::of($valor)->squish();
        // Primeira letra maiúscula, resto minúsculo (ex: “INOX” -> “Inox”)
        return ucfirst(mb_strtolower($val));
    }

    /** Parseia XML, devolve NotaDTO + coleção de ProdutoImportadoDTO */
    public function parsearXml(UploadedFile $arquivo): array
    {
        $xmlString = file_get_contents($arquivo->getRealPath());
        $xml = simplexml_load_string($xmlString);
        $ns = $xml->getNamespaces(true);

        $nfeNode = isset($xml->NFe) ? $xml->NFe : ($xml->children($ns[''])->NFe ?? null);
        $infNFe = $nfeNode ? $nfeNode->infNFe : $xml->children($ns[''])->NFe->infNFe;

        $nota = new NotaDTO(
            numero: (string)$infNFe->ide->nNF,
            dataEmissao: (string)$infNFe->ide->dhEmi ?: null,
            fornecedorCnpj: (string)$infNFe->emit->CNPJ ?: null,
            fornecedorNome: (string)$infNFe->emit->xNome ?: null,
        );

        $produtos = [];

        foreach ($infNFe->det as $det) {
            $p = $det->prod;
            $descricaoXml = (string)$p->xProd;

            [$nome, $atributosArr] = array_values(self::parseDescricao($descricaoXml));

            // 🔹 Normaliza os atributos extraídos
            $atributosArr = collect($atributosArr)
                ->map(fn($a) => [
                    'atributo' => self::normalizarAtributo($a['atributo'] ?? ''),
                    'valor'    => self::normalizarValor($a['valor'] ?? ''),
                ])
                ->unique(fn($a) => $a['atributo'] . $a['valor'])
                ->values()
                ->toArray();

            $atributosDto = array_map(
                fn(array $a) => new AtributoDTO($a['atributo'], $a['valor']),
                $atributosArr ?? []
            );

            $ref = RefHelpers::formatarReferencia((string)$p->cProd);

            $variacao = $ref
                ? ProdutoVariacao::query()
                    ->where('referencia', $ref)
                    ->with(['produto', 'atributos'])
                    ->first()
                : null;

            if ($variacao) {
                $produtoModel = $variacao->produto;
                $nome = $produtoModel->nome;

                // mantém atributos já cadastrados
                $atributosArr = $variacao->atributos->map(fn($a) => [
                    'atributo' => $a->atributo,
                    'valor'    => $a->valor,
                ])->toArray();

                $atributosDto = array_map(
                    fn(array $a) => new AtributoDTO($a['atributo'], $a['valor']),
                    $atributosArr
                );

                // 🔹 NOVO: categoria herdada do produto cadastrado
                $categoriaId = $produtoModel->id_categoria ?? null;
            } else {
                $categoriaId = null;
            }

            $produtos[] = new ProdutoImportadoDTO(
                descricaoXml: $descricaoXml,
                referencia: $ref ?: null,
                unidade: (string)$p->uCom ?: null,
                quantidade: (float)$p->qCom,
                custoUnitXml: (float)$p->vUnCom,
                valorTotalXml: (float)$p->vProd,
                observacao: (string)($det->infAdProd ?? ''),
                idCategoria: $categoriaId,
                variacaoIdManual: null,
                variacaoIdEncontrada: $variacao?->id,
                precoCadastrado: $variacao?->preco,
                custoCadastrado: $variacao?->custo,
                descricaoFinal: $nome ?: $descricaoXml,
                atributos: $atributosDto,
                pedidoId: null,
            );
        }

        return [$nota, collect($produtos), $xmlString];
    }

    /** Confirma importação: upsert produtos, movimenta estoque e salva arquivo */
    public function confirmar(NotaDTO $nota, Collection $itens, int $depositoId, string $xmlString, string $dataEntrada): void
    {
        DB::transaction(function () use ($nota, $itens, $depositoId, $xmlString, $dataEntrada) {
            $path = 'importacoes/xml/' . now()->format('Ymd-His') . "-NF{$nota->numero}.xml";
            Storage::disk('local')->put($path, $xmlString);

            foreach ($itens as $dto) {
                /** @var ProdutoImportadoDTO $dto */
                $variacaoId = $dto->variacaoIdEncontrada ?: $dto->variacaoIdManual;

                if (!$variacaoId) {
                    if (!$dto->idCategoria) {
                        throw new \RuntimeException("Categoria não informada para o produto: {$dto->descricaoXml}");
                    }

                    $produto = Produto::create([
                        'nome'         => $dto->descricaoFinal ?: $dto->descricaoXml,
                        'descricao'    => $dto->observacao ?: null,
                        'id_categoria' => $dto->idCategoria,
                        'id_fornecedor'=> null,
                        'ativo'        => true,
                    ]);

                    $variacao = $produto->variacoes()->create([
                        'referencia'   => $dto->referencia,
                        'nome'         => $dto->descricaoFinal ?: $dto->descricaoXml,
                        'preco'        => $dto->precoCadastrado ?? 0,
                        'custo'        => $dto->custoUnitXml,
                        'codigo_barras'=> null,
                    ]);

                    foreach ($dto->atributos as $attr) {
                        ProdutoVariacaoAtributo::create([
                            'id_variacao' => $variacao->id,
                            'atributo'    => self::normalizarAtributo(is_array($attr) ? $attr['atributo'] : $attr->atributo),
                            'valor'       => self::normalizarValor(is_array($attr) ? $attr['valor'] : $attr->valor),
                        ]);
                    }

                    $variacaoId = $variacao->id;
                } else {
                    $variacao = ProdutoVariacao::with('produto')->findOrFail($variacaoId);

                    if ($dto->referencia && $dto->referencia !== $variacao->referencia) {
                        $variacao->referencia = $dto->referencia;
                    }

                    if ($dto->descricaoFinal && $dto->descricaoFinal !== $variacao->produto->nome) {
                        $variacao->produto->nome = $dto->descricaoFinal;
                        $variacao->produto->save();
                    }

                    if ($dto->custoUnitXml !== null) {
                        $variacao->custo = $dto->custoUnitXml;
                    }
                    if ($dto->precoCadastrado !== null) {
                        $variacao->preco = $dto->precoCadastrado;
                    }

                    $variacao->save();

                    if (!empty($dto->atributos)) {
                        $variacao->atributos()->delete();
                        foreach ($dto->atributos as $attr) {
                            ProdutoVariacaoAtributo::create([
                                'id_variacao' => $variacao->id,
                                'atributo'    => self::normalizarAtributo(is_array($attr) ? $attr['atributo'] : $attr->atributo),
                                'valor'       => self::normalizarValor(is_array($attr) ? $attr['valor'] : $attr->valor),
                            ]);
                        }
                    }
                }

                EstoqueMovimentacao::create([
                    'id_variacao'          => $variacaoId,
                    'id_deposito_origem'   => null,
                    'id_deposito_destino'  => $depositoId,
                    'tipo'                 => 'entrada',
                    'quantidade'           => $dto->quantidade,
                    'observacao'           => 'Importação NF-e nº ' . $nota->numero,
                    'data_movimentacao'    => $dataEntrada ?: now(),
                    'id_usuario'           => Auth::id(),
                ]);
            }
        });
    }

    /** Faz o parsing da descrição (nome + atributos) */
    public static function parseDescricao(string $descricao): array
    {
        // Mantém lógica atual de parsing
        $descricao = trim(preg_replace('/\s+/', ' ', strtoupper($descricao)));

        preg_match('/(\d{2,3}X\d{2,3}X\d{2,3})\s?CM?/', $descricao, $dimMatch);
        $dimensoes = $dimMatch[1] ?? null;

        $partes = preg_split('/\s*-\s*/', $descricao);
        $nomeBase = trim(array_shift($partes));
        $atributos = [];

        $mapCor = [
            'CORIN', 'COR INOX', 'COR INOXMT', 'COR INOXGOLD', 'CORAC25', 'CORPR', 'COR COBRE',
            'COR INOXMT', 'COR INOX GOLD', 'COR AC', 'COR AC25', 'COR BRANCO', 'COR PRETO',
        ];
        $mapTampo = ['TAMPO VIDRO', 'TAMPO DE VIDRO'];
        $mapMaterial = ['VIDROMT', 'CRMT', 'ACMT', 'PRMT', 'MT'];
        $mapAcabamento = ['INOX', 'GOLD', 'COBRE', 'PRETO', 'BRANCO', 'ESCOVADO'];

        foreach ($partes as $p) {
            $p = trim($p);

            foreach ($mapCor as $c) {
                if (str_contains($p, $c)) {
                    $atributos[] = ['atributo' => 'Cor', 'valor' => trim(str_replace(['COR', 'IN', 'X'], '', $c))];
                    break;
                }
            }

            foreach ($mapTampo as $t) {
                if (str_contains($p, $t)) {
                    $atributos[] = ['atributo' => 'Tampo', 'valor' => 'Vidro'];
                    break;
                }
            }

            foreach ($mapMaterial as $m) {
                if (str_contains($p, $m)) {
                    $atributos[] = ['atributo' => 'Material', 'valor' => $m];
                    break;
                }
            }

            foreach ($mapAcabamento as $a) {
                if (preg_match("/\b$a\b/i", $p)) {
                    $atributos[] = ['atributo' => 'Acabamento', 'valor' => ucfirst(strtolower($a))];
                    break;
                }
            }

            if ($dimensoes && !collect($atributos)->contains(fn($attr) => ($attr['atributo'] ?? null) === 'Dimensões')) {
                $atributos[] = ['atributo' => 'Dimensões', 'valor' => str_replace('X', 'x', $dimensoes) . ' cm'];
            }
        }

        $atributos = collect($atributos)
            ->unique(fn($a) => ($a['atributo'] ?? '') . ($a['valor'] ?? ''))
            ->values()
            ->toArray();

        return [
            'nome' => trim($nomeBase),
            'atributos' => $atributos,
        ];
    }
}
