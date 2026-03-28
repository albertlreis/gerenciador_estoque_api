<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\ProdutoVariacaoCodigoHistorico;
use App\Models\PedidoImportacao;
use App\Models\Categoria;
use App\Enums\EstrategiaVinculoImportacao;
use App\Enums\PedidoStatus;
use App\Enums\TipoImportacao;
use App\Helpers\StringHelper;
use App\Support\Dates\DateNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Serviço responsável pela importação de pedidos via XML.
 */
class ImportacaoPedidoService
{
    private ?int $categoriaPadraoId = null;

    /**
     * Confirma os dados da importação de um pedido, salvando no banco.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function confirmarImportacaoPDF(Request $request): JsonResponse
    {
        Log::info('Importação XML - confirmação iniciada', [
            'usuario_id' => Auth::id(),
            'importacao_id' => $request->input('importacao_id'),
            'itens_total' => is_array($request->input('itens')) ? count($request->input('itens')) : 0,
        ]);

        $validator = Validator::make($request->all(), [
            'pedido.tipo'          => 'required|in:venda,reposicao',
            'importacao_id'        => 'nullable|integer|exists:pedido_importacoes,id',
            'tipo_importacao'      => 'nullable|in:' . implode(',', TipoImportacao::valores()),

            'cliente.id'           => 'nullable|numeric|min:1',

            'pedido.numero_externo'=> 'nullable|string|max:50|unique:pedidos,numero_externo',
            'pedido.total'         => 'nullable|numeric',
            'pedido.observacoes'   => 'nullable|string',
            'pedido.data_pedido'   => 'nullable|string',
            'pedido.data_inclusao' => 'nullable|string',
            'pedido.data_entrega'  => 'nullable|string',
            'pedido.entregue'      => 'nullable|boolean',
            'pedido.previsao_tipo' => 'nullable|in:DATA,DIAS_UTEIS,DIAS_CORRIDOS',
            'pedido.data_prevista' => 'nullable|string',
            'pedido.dias_uteis_previstos' => 'nullable|integer|min:0|max:3650',
            'pedido.dias_corridos_previstos' => 'nullable|integer|min:0|max:3650',

            'entregue'             => 'nullable|boolean',
            'data_entrega'         => 'nullable|string',
            'previsao_tipo'        => 'nullable|in:DATA,DIAS_UTEIS,DIAS_CORRIDOS',
            'data_prevista'        => 'nullable|string',
            'dias_uteis_previstos' => 'nullable|integer|min:0|max:3650',
            'dias_corridos_previstos' => 'nullable|integer|min:0|max:3650',

            'itens'                => 'required|array|min:1',
            'itens.*.nome'         => 'required|string',
            'itens.*.quantidade'   => 'required|numeric|min:0.01',
            'itens.*.valor'        => 'required|numeric|min:0',
            'itens.*.preco_unitario' => 'nullable|numeric|min:0',
            'itens.*.custo_unitario' => 'nullable|numeric|min:0',
            'itens.*.id_categoria' => 'required|integer',
            'itens.*.id_deposito'  => 'nullable|integer|exists:depositos,id',
            'estrategia_vinculo'   => 'nullable|in:' . implode(',', EstrategiaVinculoImportacao::valores()),
            'itens.*.forcar_produto_novo' => 'nullable|boolean',
        ], [
            'itens.required' => 'Adicione ao menos um item ao pedido antes de confirmar.',
            'itens.min' => 'Adicione ao menos um item ao pedido (inserção manual) antes de confirmar.',
        ]);

        // Condicional: se for venda, cliente.id é obrigatório
        $validator->sometimes('cliente.id', 'required|numeric|min:1', function ($input) {
            return data_get($input, 'pedido.tipo') === Pedido::TIPO_VENDA;
        });

        if ($validator->fails()) {
            Log::warning('Importação XML - validação falhou', [
                'usuario_id' => Auth::id(),
                'erros' => $validator->errors()->toArray(),
            ]);
            throw new ValidationException($validator);
        }

        try {
            return DB::transaction(function () use ($request) {
            $usuario     = Auth::user();
            $dadosCliente = (array) $request->cliente;
            $dadosPedido  = (array) $request->pedido;
            $itens        = (array) $request->itens;
            $importacaoId = $request->input('importacao_id');

            $tipo = $dadosPedido['tipo'] ?? Pedido::TIPO_VENDA;

            if ($importacaoId) {
                /** @var PedidoImportacao $importacao */
                $importacao = PedidoImportacao::query()
                    ->lockForUpdate()
                    ->findOrFail((int) $importacaoId);

                if ($importacao->status === 'confirmado') {
                    return response()->json([
                        'message' => 'Esta importação já foi confirmada anteriormente.',
                        'pedido_id' => $importacao->pedido_id,
                    ], 409);
                }
            }

            $clienteId = null;

            if ($tipo === Pedido::TIPO_VENDA) {
                $dadosCliente['documento'] = preg_replace('/\D/', '', $dadosCliente['documento'] ?? '');
                $dadosCliente['nome'] = isset($dadosCliente['nome'])
                    ? trim((string) $dadosCliente['nome'])
                    : null;
                $dadosCliente['email'] = isset($dadosCliente['email'])
                    ? trim((string) $dadosCliente['email'])
                    : null;
                $dadosCliente['telefone'] = isset($dadosCliente['telefone'])
                    ? preg_replace('/\D/', '', (string) $dadosCliente['telefone'])
                    : null;

                if (!empty($dadosCliente['id'])) {
                    $cliente = Cliente::findOrFail((int) data_get($request->cliente, 'id'));
                } else {
                    $cliente = Cliente::firstOrCreate(
                        ['documento' => $dadosCliente['documento']],
                        [
                            'nome'     => $dadosCliente['nome'] ?? 'Cliente',
                            'email'    => $dadosCliente['email'] ?? null,
                            'telefone' => $dadosCliente['telefone'] ?? null,
                            'endereco' => $dadosCliente['endereco'] ?? null,
                        ]
                    );
                }

                $clienteId = $cliente->id;
            }

            $valorTotal = $dadosPedido['total']
                ?? collect($itens)->sum(fn($i) => (float) ($i['quantidade'] ?? 0) * (float) ($i['valor'] ?? 0));

            $numeroExterno = isset($dadosPedido['numero_externo'])
                ? trim((string) $dadosPedido['numero_externo'])
                : null;

            $dataPedidoNormalizada = DateNormalizer::normalizeDate($dadosPedido['data_pedido'] ?? null, 'pedido.data_pedido');
            $dataInclusao = DateNormalizer::normalizeDate($dadosPedido['data_inclusao'] ?? null, 'pedido.data_inclusao');
            $dataBasePedido = $dataPedidoNormalizada ?? $dataInclusao ?? CarbonImmutable::now(config('app.timezone'));

            $previsaoTipo = $this->normalizePrevisaoTipo(
                $request->input('previsao_tipo') ?? ($dadosPedido['previsao_tipo'] ?? null)
            );
            $diasUteisPrevistos = $this->toNullableInt(
                $request->input('dias_uteis_previstos') ?? ($dadosPedido['dias_uteis_previstos'] ?? null)
            );
            $diasCorridosPrevistos = $this->toNullableInt(
                $request->input('dias_corridos_previstos') ?? ($dadosPedido['dias_corridos_previstos'] ?? null)
            );

            $dataPrevista = DateNormalizer::normalizeDate(
                $request->input('data_prevista') ?? ($dadosPedido['data_prevista'] ?? null),
                'data_prevista'
            );

            $entregue = $this->toBoolean($request->input('entregue', $dadosPedido['entregue'] ?? false));
            $dataEntregaTopLevel = $request->input('data_entrega');
            $dataEntregaPedidoLegado = $dadosPedido['data_entrega'] ?? null;
            $dataEntrega = DateNormalizer::normalizeDate(
                $dataEntregaTopLevel ?? $dataEntregaPedidoLegado,
                'data_entrega'
            );

            if (!$previsaoTipo && !$dataPrevista && !$entregue && $this->hasValue($dataEntregaPedidoLegado)) {
                $previsaoTipo = 'DATA';
                $dataPrevista = DateNormalizer::normalizeDate($dataEntregaPedidoLegado, 'pedido.data_entrega');
                $dataEntrega = null;
            }

            if ($entregue && !$dataEntrega) {
                throw ValidationException::withMessages([
                    'data_entrega' => ['Informe a data de entrega quando o pedido já foi entregue.'],
                ]);
            }

            if ($previsaoTipo === 'DATA' && !$dataPrevista) {
                throw ValidationException::withMessages([
                    'data_prevista' => ['Informe a data prevista quando o tipo de previsão for DATA.'],
                ]);
            }

            if ($previsaoTipo === 'DIAS_UTEIS' && $diasUteisPrevistos === null) {
                throw ValidationException::withMessages([
                    'dias_uteis_previstos' => ['Informe os dias úteis previstos.'],
                ]);
            }

            if ($previsaoTipo === 'DIAS_CORRIDOS' && $diasCorridosPrevistos === null) {
                throw ValidationException::withMessages([
                    'dias_corridos_previstos' => ['Informe os dias corridos previstos.'],
                ]);
            }

            if ($dataEntrega && $dataEntrega->startOfDay()->lt($dataBasePedido->startOfDay())) {
                throw ValidationException::withMessages([
                    'data_entrega' => ['A data de entrega não pode ser anterior à data do pedido.'],
                ]);
            }

            $entregaPrevista = $this->resolverEntregaPrevista(
                $previsaoTipo,
                $dataBasePedido,
                $dataPrevista,
                $diasUteisPrevistos,
                $diasCorridosPrevistos
            );

            $pedidoPayload = [
                'tipo'          => $tipo,
                'id_cliente'    => $clienteId,
                'id_usuario'    => $usuario->id,
                'id_parceiro'   => $dadosPedido['id_parceiro'] ?? null,
                'numero_externo'=> $numeroExterno ?: null,
                'data_pedido'   => $dataBasePedido->toDateTimeString(),
                'valor_total'   => $valorTotal,
                'observacoes'   => $dadosPedido['observacoes'] ?? null,
            ];

            if ($entregaPrevista) {
                $pedidoPayload['data_limite_entrega'] = $entregaPrevista->toDateString();
            }

            if ($previsaoTipo === 'DIAS_UTEIS' && $diasUteisPrevistos !== null) {
                $pedidoPayload['prazo_dias_uteis'] = $diasUteisPrevistos;
            }

            $pedido = Pedido::create($pedidoPayload);

            PedidoStatusHistorico::create([
                'pedido_id'   => $pedido->id,
                'status'      => PedidoStatus::PEDIDO_CRIADO,
                'data_status' => $dataBasePedido->toDateTimeString(),
                'usuario_id'  => $usuario->id,
            ]);

            if ($entregue) {
                PedidoStatusHistorico::create([
                    'pedido_id'   => $pedido->id,
                    'status'      => PedidoStatus::ENTREGA_CLIENTE,
                    'data_status' => $dataEntrega?->toDateTimeString(),
                    'usuario_id'  => $usuario->id,
                    'observacoes' => 'Status aplicado na confirmação da importação PDF.',
                ]);
            }

            foreach ($itens as $index => $item) {
                $item['nome'] = $this->normalizarNomeItem($item['nome'] ?? '');
                $item['ref'] = isset($item['ref']) ? trim((string) $item['ref']) : null;
                $item['id_deposito'] = $item['id_deposito'] ?? null;

                $quantidade = $this->toDecimal($item['quantidade'] ?? 0);
                $precoUnitarioFonte = $item['preco_unitario'] ?? ($item['preco'] ?? null);

                if (!$this->hasValue($item['custo_unitario'] ?? null) && !$this->hasValue($precoUnitarioFonte)) {
                    throw ValidationException::withMessages([
                        "itens.$index.preco_unitario" => [
                            'Preço unitário obrigatório para definir o custo do item importado.',
                        ],
                    ]);
                }

                $valorUnit = $this->toDecimal($item['valor'] ?? $precoUnitarioFonte);
                $custoUnit = $this->toDecimal(
                    $this->hasValue($item['custo_unitario'] ?? null)
                        ? $item['custo_unitario']
                        : $precoUnitarioFonte
                );

                if ($index < 3) {
                    Log::info('Importação XML - item normalizado para persistência', [
                        'index' => $index,
                        'referencia' => $item['ref'] ?? null,
                        'quantidade' => $quantidade,
                        'preco_unitario' => $valorUnit,
                        'custo_unitario' => $custoUnit,
                        'valor_total_linha' => $this->toDecimal($item['valor_total_linha'] ?? ($item['valor_total'] ?? 0)),
                        'forcar_produto_novo' => $this->itemDeveForcarProdutoNovo($request, $item),
                    ]);
                }

                $forcarProdutoNovo = $this->itemDeveForcarProdutoNovo($request, $item);

                $variacao = null;

                if (!$forcarProdutoNovo && !empty($item['id_variacao'])) {
                    $variacao = ProdutoVariacao::with('atributos')->find($item['id_variacao']);
                }

                if (
                    !$forcarProdutoNovo
                    && !$variacao
                    && !empty($item['codigo_barras'])
                ) {
                    $variacao = ProdutoVariacao::with('atributos')
                        ->where('codigo_barras', trim((string) $item['codigo_barras']))
                        ->first();
                }

                if (!$forcarProdutoNovo && !$variacao && !empty($item['ref'])) {
                    // A referência pode NÃO ser única por variação.
                    // Regra: se for ambígua e o front não enviou `id_variacao`,
                    // não devemos "chutar" uma variação automaticamente.
                    $variacoesPorIdentificador = ProdutoVariacao::with('atributos')
                        ->where(function ($query) use ($item) {
                            $this->aplicarBuscaPorIdentificador($query, (string) ($item['ref'] ?? ''));
                        })
                        ->get();

                    if ($variacoesPorIdentificador->count() === 1) {
                        $variacao = $variacoesPorIdentificador->first();
                    } elseif ($variacoesPorIdentificador->count() > 1) {
                        throw ValidationException::withMessages([
                            "itens.{$index}.selecao_variacao" => [
                                'A referência informada corresponde a múltiplas variações. Selecione a variação correta na tela de importação.',
                            ],
                        ]);
                    }
                }

                if (!$variacao) {
                    $produto = Produto::firstOrCreate([
                        'nome'         => $item['nome'],
                        'id_categoria' => $item['id_categoria'],
                    ]);

                    $variacao = ProdutoVariacao::create([
                        'produto_id' => $produto->id,
                        'referencia' => $item['ref'] ?? null,
                        'sku_interno' => $item['sku_interno'] ?? null,
                        'nome'       => $item['nome'],
                        'preco'      => $valorUnit,
                        'custo'      => $custoUnit,
                    ]);

                    foreach ($item['atributos'] ?? [] as $atrib => $valor) {
                        if (is_array($valor)) continue;
                        if (is_numeric($valor)) $valor = (string) $valor;
                        if ($valor === null || trim((string)$valor) === '') continue;

                        ProdutoVariacaoAtributo::updateOrCreate(
                            [
                                'id_variacao' => $variacao->id,
                                'atributo'    => StringHelper::normalizarAtributo($atrib),
                            ],
                            ['valor' => trim((string)$valor)]
                        );
                    }
                }

                $this->registrarCodigoHistoricoPedido($variacao, $item['ref'] ?? null);

                PedidoItem::create([
                    'id_pedido'      => $pedido->id,
                    'id_variacao'    => $variacao->id,
                    'quantidade'     => $quantidade,
                    'preco_unitario' => $valorUnit,
                    'custo_unitario' => $custoUnit,
                    'subtotal'       => (float)$quantidade * (float)$valorUnit,
                    'id_deposito'    => $item['id_deposito'] ?? null,
                    'observacoes'    => $item['atributos']['observacao'] ?? null,
                ]);
            }

            if (isset($importacao)) {
                $importacao->update([
                    'status' => 'confirmado',
                    'pedido_id' => $pedido->id,
                    'numero_externo' => $numeroExterno ?: $importacao->numero_externo,
                ]);
            }

            Log::info('Importação XML - pedido confirmado', [
                'usuario_id' => $usuario->id,
                'pedido_id' => $pedido->id,
                'importacao_id' => $importacaoId,
                'itens_total' => count($itens),
            ]);

            $itensConfirmados = $pedido->itens()
                ->with('variacao.produto', 'variacao.atributos')
                ->get();

            return response()->json([
                'message' => 'Pedido importado e salvo com sucesso.',
                'id'      => $pedido->id,
                'tipo'    => $pedido->tipo,
                'itens'   => $itensConfirmados->map(function ($item) {
                    return [
                        'id_variacao'   => $item->variacao?->id,
                        'referencia'    => $item->variacao?->referencia,
                        'sku_interno'   => $item->variacao?->sku_interno,
                        'nome_produto'  => $item->variacao?->produto?->nome,
                        'nome_completo' => $item->variacao?->nomeCompleto,
                        'categoria_id'  => $item->variacao?->produto?->id_categoria,
                    ];
                }),
            ]);
            });
        } catch (ValidationException $e) {
            Log::warning('Importação XML - erro de normalização/validação', [
                'usuario_id' => Auth::id(),
                'erros' => $e->errors(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Importação XML - erro ao confirmar', [
                'usuario_id' => Auth::id(),
                'mensagem' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            Log::info('Importação XML - confirmação finalizada', [
                'usuario_id' => Auth::id(),
                'importacao_id' => $request->input('importacao_id'),
            ]);
        }
    }

    private function normalizarNomeItem(mixed $nome): string
    {
        $valor = trim(preg_replace('/\s+/u', ' ', (string) $nome));

        // Evita erro de persistência (Data too long for column `nome`) quando o extrator
        // devolve uma descrição acidentalmente concatenada em um único item.
        if ($valor === '') {
            return 'ITEM IMPORTADO';
        }

        return mb_substr($valor, 0, 255);
    }

    private function toDecimal(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }

        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        $s = preg_replace('/[^\d,.\-]/', '', trim((string) $v));
        if ($s === null || $s === '' || $s === '-' || $s === '.' || $s === ',') {
            return 0.0;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float)$s : 0.0;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function normalizePrevisaoTipo(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['DATA', 'DIAS_UTEIS', 'DIAS_CORRIDOS'], true)
            ? $normalized
            : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function resolverEntregaPrevista(
        ?string $previsaoTipo,
        CarbonImmutable $dataBasePedido,
        ?CarbonImmutable $dataPrevista,
        ?int $diasUteisPrevistos,
        ?int $diasCorridosPrevistos
    ): ?CarbonImmutable {
        if ($previsaoTipo === null) {
            return null;
        }

        return match ($previsaoTipo) {
            'DATA' => $dataPrevista?->startOfDay(),
            'DIAS_UTEIS' => $dataBasePedido->startOfDay()->addWeekdays(max(0, (int) ($diasUteisPrevistos ?? 0))),
            'DIAS_CORRIDOS' => $dataBasePedido->startOfDay()->addDays(max(0, (int) ($diasCorridosPrevistos ?? 0))),
            default => null,
        };
    }

    private function hasValue(mixed $value): bool
    {
        return !($value === null || (is_string($value) && trim($value) === ''));
    }

    private function aplicarBuscaPorIdentificador($query, string $identificador): void
    {
        $query->where('sku_interno', $identificador)
            ->orWhere('referencia', $identificador)
            ->orWhereHas('codigosHistoricos', function ($codigoQuery) use ($identificador) {
                $codigoQuery->where('codigo', $identificador)
                    ->orWhere('codigo_origem', $identificador)
                    ->orWhere('codigo_modelo', $identificador);
            });
    }

    private function localizarVariacaoPorIdentificador(string $identificador): ?ProdutoVariacao
    {
        return ProdutoVariacao::with('atributos')
            ->where(function ($query) use ($identificador) {
                $this->aplicarBuscaPorIdentificador($query, $identificador);
            })
            ->first();
    }

    private function registrarCodigoHistoricoPedido(ProdutoVariacao $variacao, ?string $codigo): void
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return;
        }

        ProdutoVariacaoCodigoHistorico::updateOrCreate(
            [
                'produto_variacao_id' => $variacao->id,
                'hash_conteudo' => sha1(json_encode([
                    'codigo' => $codigo,
                    'codigo_origem' => $codigo,
                    'fonte' => 'importacao_pedido_xml',
                ], JSON_UNESCAPED_UNICODE)),
            ],
            [
                'codigo' => $codigo,
                'codigo_origem' => $codigo,
                'codigo_modelo' => null,
                'fonte' => 'importacao_pedido_xml',
                'aba_origem' => null,
                'observacoes' => 'Importacao de pedido XML',
                'principal' => false,
            ]
        );
    }

    /**
     * Mescla itens extraídos do PDF com itens já cadastrados.
     *
     * - Enriquece com nome_completo
     * - Envia atributos da variação
     * - Envia dimensões do produto (largura, profundidade, altura) em "fixos"
     *
     * @param array $itens
     * @return array
     */
    public function mesclarItensComVariacoes(array $itens, ?string $estrategiaVinculo = null): array
    {
        return collect($itens)->values()->map(function ($item, int $index) {
            $linha = $this->normalizarLinhaItem($item['linha'] ?? null, $index + 1);

            $ref = isset($item['codigo']) && trim((string) $item['codigo']) !== ''
                ? trim((string) $item['codigo'])
                : (isset($item['ref']) ? trim((string) $item['ref']) : null);
            $codigoBarras = isset($item['codigo_barras']) ? trim((string) $item['codigo_barras']) : null;

            if (!$ref && !$codigoBarras) {
                $item['linha'] = $linha;
                return $item;
            }

            // 1) Código de barras (quando existe) segue fluxo simples (tende a ser único)
            $variacaoQuery = ProdutoVariacao::with(['produto.categoria', 'atributos']);
            if ($codigoBarras) {
                $variacao = $variacaoQuery
                    ->where('codigo_barras', $codigoBarras)
                    ->first();

                if ($variacao) {
                    return $this->mapearItemComVariacaoEncontrada($item, $linha, $ref, $variacao);
                }
            }

            // 2) Para identificadores gerais, mantém o comportamento antigo (SKU interno/códigos históricos)
            //    mas NÃO assume unicidade de referência legada.
            if ($ref) {
                // 2.1 Referência legada: NÃO é única por variação → retorna lista quando houver ambiguidade.
                // Prioriza `referencia` para evitar "chutar" variação a partir de outros campos
                // quando o valor realmente representa apenas a referência legada do PDF.
                $variacoesPorReferencia = ProdutoVariacao::with(['produto.categoria', 'atributos'])
                    ->where('referencia', $ref)
                    ->get();

                $variacoesEncontradas = $this->variacoesParaListaPreview($variacoesPorReferencia);

                if ($variacoesPorReferencia->count() === 1) {
                    $variacaoUnica = $variacoesPorReferencia->first();
                    $itemMapeado = $this->mapearItemComVariacaoEncontrada($item, $linha, $ref, $variacaoUnica);

                    // Contrato: referencia deve retornar TODAS as variações relacionadas à referencia informada.
                    // Mesmo quando há apenas 1, entregamos a lista para o front manter estado consistente.
                    return array_merge($itemMapeado, [
                        'variacoes_encontradas' => $variacoesEncontradas,
                    ]);
                }

                if ($variacoesPorReferencia->count() > 1) {
                    $categoriaId = $item['id_categoria'] ?? $this->categoriaPadraoImportacaoId();
                    $categoriaNome = $item['categoria'] ?? $this->categoriaPadraoNome($categoriaId);

                    return array_merge($item, [
                        "linha" => $linha,
                        "ref" => $ref,
                        "produto_id" => null,
                        "id_variacao" => null,
                        "variacao_nome" => null,
                        "nome_completo" => null,
                        "id_categoria" => $categoriaId,
                        "categoria" => $categoriaNome,
                        "atributos" => $item['atributos'] ?? [],
                        "fixos" => $item['fixos'] ?? [],
                        // lista de variações para seleção explícita no front
                        "variacoes_encontradas" => $variacoesEncontradas,
                    ]);
                }

                // Se não houver correspondência por referência, mantém comportamento legado
                // (SKU interno/códigos históricos) para casos em que o PDF trouxe outro identificador.
                // 2.2 SKU interno tem unicidade → pode selecionar direto
                $variacaoSku = ProdutoVariacao::with(['produto.categoria', 'atributos'])
                    ->where('sku_interno', $ref)
                    ->first();

                if ($variacaoSku) {
                    return $this->mapearItemComVariacaoEncontrada($item, $linha, $ref, $variacaoSku);
                }

                // 2.3 Códigos históricos (geralmente únicos)
                $variacaoHistorico = ProdutoVariacao::with(['produto.categoria', 'atributos'])
                    ->whereHas('codigosHistoricos', function ($q) use ($ref) {
                        $q->where('codigo', $ref)
                            ->orWhere('codigo_origem', $ref)
                            ->orWhere('codigo_modelo', $ref);
                    })
                    ->first();

                if ($variacaoHistorico) {
                    return $this->mapearItemComVariacaoEncontrada($item, $linha, $ref, $variacaoHistorico);
                }
            }

            // Produto não encontrado → usar dados do PDF
            $categoriaId = $item['id_categoria'] ?? $this->categoriaPadraoImportacaoId();

            return array_merge($item, [
                "linha"         => $linha,
                "ref"           => $ref,
                "produto_id"    => null,
                "id_variacao"   => null,
                "variacao_nome" => null,
                "id_categoria"  => $categoriaId,
                "categoria"  => $item['categoria'] ?? $this->categoriaPadraoNome($categoriaId),
                "atributos"     => $item['atributos'] ?? [],
                "fixos"         => $item['fixos'] ?? [],
            ]);
        })->toArray();
    }

    private function mapearItemComVariacaoEncontrada(array $item, int $linha, ?string $ref, ProdutoVariacao $variacao): array
    {
        $produto = $variacao->produto;

        $atributosVariacao = $variacao->relationLoaded('atributos')
            ? $variacao->atributos->mapWithKeys(fn($attr) => [$attr->atributo => $attr->valor])->toArray()
            : [];

        // Atributos vindos do PDF (se houver) – db sobrescreve o que vier errado
        $atributosPdf = $item['atributos'] ?? [];
        $atributosFinal = array_merge($atributosPdf, $atributosVariacao);

        // Dimensões vindas do produto
        $fixosDb = [
            'largura' => $produto?->largura,
            'profundidade' => $produto?->profundidade,
            'altura' => $produto?->altura,
        ];

        $fixosPdf = $item['fixos'] ?? [];
        $fixosFinal = array_merge(
            $fixosPdf,
            array_filter($fixosDb, fn($v) => !is_null($v))
        );

        $categoriaId = $produto?->id_categoria ?? $this->categoriaPadraoImportacaoId();
        $categoriaNome = $produto?->categoria?->nome ?? $this->categoriaPadraoNome($categoriaId);

        return array_merge($item, [
            "linha" => $linha,
            "ref" => $ref,
            "sku_interno" => $variacao->sku_interno,
            "nome" => $produto?->nome ?? $variacao->nome,
            "produto_id" => $variacao->produto_id,
            "id_variacao" => $variacao->id,
            "variacao_nome" => $variacao->nome,
            "nome_completo" => $variacao->nome_completo,
            "id_categoria" => $categoriaId,
            "categoria" => $categoriaNome,
            "atributos" => $atributosFinal,
            "fixos" => $fixosFinal,
            // garante que o front não exiba seleção antiga
            "variacoes_encontradas" => [],
        ]);
    }

    private function normalizarLinhaItem(mixed $linha, int $fallback): int
    {
        if (is_int($linha) && $linha > 0) {
            return $linha;
        }

        if (is_string($linha) && ctype_digit($linha) && (int) $linha > 0) {
            return (int) $linha;
        }

        return $fallback;
    }

    private function categoriaPadraoImportacaoId(): int
    {
        if ($this->categoriaPadraoId) {
            return $this->categoriaPadraoId;
        }

        $categoria = Categoria::query()->where('nome', 'Importacao XML - Sem categoria')->first();

        if (!$categoria) {
            $categoria = Categoria::query()->create([
                'nome' => 'Importacao XML - Sem categoria',
            ]);
        }

        $this->categoriaPadraoId = (int) $categoria->id;
        return $this->categoriaPadraoId;
    }

    private function categoriaPadraoNome(int $categoriaId): ?string
    {
        return Categoria::query()->whereKey($categoriaId)->value('nome');
    }

    /**
     * @param Collection<int, ProdutoVariacao> $variacoesPorReferencia
     * @return list<array<string, mixed>>
     */
    private function variacoesParaListaPreview(Collection $variacoesPorReferencia): array
    {
        return $variacoesPorReferencia->map(function (ProdutoVariacao $v) {
            $produto = $v->produto;
            $categoriaId = $produto?->id_categoria;

            $atributos = $v->relationLoaded('atributos')
                ? $v->atributos->mapWithKeys(fn ($a) => [$a->atributo => $a->valor])->toArray()
                : [];

            $fixosDb = [
                'largura' => $produto?->largura,
                'profundidade' => $produto?->profundidade,
                'altura' => $produto?->altura,
            ];

            return [
                'id_variacao' => $v->id,
                'produto_id' => $v->produto_id,
                'sku_interno' => $v->sku_interno,
                'referencia' => $v->referencia,
                'variacao_nome' => $v->nome,
                'nome_produto' => $produto?->nome ?? null,
                'nome_completo' => $v->nome_completo,
                'id_categoria' => $categoriaId,
                'categoria' => $produto?->categoria?->nome ?? null,
                'atributos' => $atributos,
                'fixos' => array_filter($fixosDb, fn ($val) => !is_null($val)),
            ];
        })->values()->toArray();
    }

    private function itemDeveForcarProdutoNovo(Request $request, array $item): bool
    {
        return filter_var($item['forcar_produto_novo'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
