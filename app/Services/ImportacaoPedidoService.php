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
use App\Models\PedidoImportacaoItem;
use App\Models\ProdutoEntregaEvento;
use App\Models\Categoria;
use App\Enums\EstrategiaVinculoImportacao;
use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Enums\TipoImportacao;
use App\Helpers\StringHelper;
use App\Support\Dates\DateNormalizer;
use App\Support\Logging\SierraLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Serviço responsável pela importação de pedidos via XML.
 */
class ImportacaoPedidoService
{
    private const PRAZO_IMPORTACAO_PADRAO_DIAS_UTEIS = 60;
    private const MOVIMENTACAO_ENTRADA = 'entrada';
    private const MOVIMENTACAO_SAIDA = 'saida';
    private const CATEGORIA_IMPORTACAO_SEM_CATEGORIA = 'Importacao XML - Sem categoria';
    private const MENSAGEM_CATEGORIA_IMPORTACAO_INVALIDA = 'Selecione uma categoria válida para o produto. A categoria "Importacao XML - Sem categoria" não é permitida.';
    private const ATRIBUTOS_FISCAIS_NFE = [
        'observacao',
        'quantidade_nfe',
        'unidade_nfe',
        'valor_unitario_nfe',
    ];

    /**
     * Confirma os dados da importação de um pedido, salvando no banco.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function confirmarImportacaoXml(Request $request): JsonResponse
    {
        SierraLog::inventory('inventory.order_xml_import.confirmation_started', [
            'usuario_id' => Auth::id(),
            'entity_type' => 'pedido_importacao',
            'entity_id' => $request->input('importacao_id'),
            'itens_total' => is_array($request->input('itens')) ? count($request->input('itens')) : 0,
        ]);

        $validator = Validator::make($request->all(), [
            'pedido.tipo'          => 'required|in:venda,reposicao',
            'importacao_id'        => 'nullable|integer|exists:pedido_importacoes,id',
            'tipo_importacao'      => 'nullable|in:' . implode(',', TipoImportacao::valores()),

            'cliente.id'           => 'nullable|numeric|min:1',

            'pedido.numero_externo'=> 'required|string|max:50',
            'pedido.id_fornecedor'  => 'nullable|integer|exists:fornecedores,id',
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
            'movimentar_estoque'    => 'nullable|boolean',
            'data_entrega'         => 'nullable|string',
            'previsao_tipo'        => 'nullable|in:DATA,DIAS_UTEIS,DIAS_CORRIDOS',
            'data_prevista'        => 'nullable|string',
            'dias_uteis_previstos' => 'nullable|integer|min:0|max:3650',
            'dias_corridos_previstos' => 'nullable|integer|min:0|max:3650',

            'itens'                => 'required|array|min:1',
            'itens.*.nome'         => 'required|string|max:255',
            'itens.*.ref'          => 'nullable|string|max:100',
            'itens.*.sku_interno'  => 'nullable|string|max:120',
            'itens.*.quantidade'   => 'required|integer|min:1',
            'itens.*.valor'        => 'required|numeric|min:0|max:99999999.99',
            'itens.*.preco_unitario' => 'nullable|numeric|min:0|max:99999999.99',
            'itens.*.custo_unitario' => 'nullable|numeric|min:0|max:99999999.99',
            'itens.*.id_categoria' => 'required|integer|exists:categorias,id',
            'itens.*.id_deposito'  => 'nullable|integer|exists:depositos,id',
            'itens.*.movimentacao_estoque_tipo' => 'nullable|in:entrada,saida',
            'itens.*.atributos'    => 'nullable|array',
            'itens.*.atributos.*'  => 'nullable',
            'itens.*.atributos_nfe' => 'nullable|array',
            'estrategia_vinculo'   => 'nullable|in:' . implode(',', EstrategiaVinculoImportacao::valores()),
            'itens.*.forcar_produto_novo' => 'nullable|boolean',
        ], [
            'itens.required' => 'Adicione ao menos um item ao pedido antes de confirmar.',
            'itens.min' => 'Adicione ao menos um item ao pedido (inserção manual) antes de confirmar.',
            'pedido.numero_externo.required' => 'Informe o número do pedido antes de confirmar.',
            'pedido.numero_externo.max' => 'Número do pedido deve ter no máximo 50 caracteres.',
            'itens.*.nome.required' => 'Informe o nome do produto.',
            'itens.*.nome.max' => 'O nome do produto deve ter no máximo 255 caracteres.',
            'itens.*.ref.max' => 'A referência do produto deve ter no máximo 100 caracteres.',
            'itens.*.sku_interno.max' => 'O SKU interno deve ter no máximo 120 caracteres.',
            'itens.*.quantidade.integer' => 'A quantidade deve ser um número inteiro maior que zero.',
            'itens.*.quantidade.min' => 'A quantidade deve ser um número inteiro maior que zero.',
            'itens.*.valor.max' => 'O preço de venda deve ser no máximo R$ 99.999.999,99.',
            'itens.*.preco_unitario.max' => 'O preço unitário deve ser no máximo R$ 99.999.999,99.',
            'itens.*.custo_unitario.max' => 'O custo unitário deve ser no máximo R$ 99.999.999,99.',
            'itens.*.id_categoria.required' => 'Selecione uma categoria para todos os produtos.',
            'itens.*.atributos.array' => 'Os atributos do produto devem ser enviados em formato válido.',
        ]);

        $validator->after(function ($validator) use ($request) {
            $itens = $request->input('itens', []);
            if (!is_array($itens)) {
                return;
            }

            $categoriaIds = collect($itens)
                ->pluck('id_categoria')
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values();

            $categoriasProibidas = $categoriaIds->isEmpty()
                ? collect()
                : Categoria::query()
                    ->whereIn('id', $categoriaIds)
                    ->where('nome', self::CATEGORIA_IMPORTACAO_SEM_CATEGORIA)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->flip();

            $variacaoIds = collect($itens)
                ->pluck('id_variacao')
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values();

            $variacoesComCategoriaProibida = $variacaoIds->isEmpty()
                ? collect()
                : ProdutoVariacao::query()
                    ->join('produtos', 'produtos.id', '=', 'produto_variacoes.produto_id')
                    ->join('categorias', 'categorias.id', '=', 'produtos.id_categoria')
                    ->whereIn('produto_variacoes.id', $variacaoIds)
                    ->where('categorias.nome', self::CATEGORIA_IMPORTACAO_SEM_CATEGORIA)
                    ->pluck('produto_variacoes.id')
                    ->map(fn ($id) => (int) $id)
                    ->flip();

            foreach ($itens as $index => $item) {
                $label = 'Produto ' . ($index + 1);
                $forcarProdutoNovo = $this->itemDeveForcarProdutoNovo($request, $item);
                $semReferencia = !$this->hasValue($item['ref'] ?? null);
                $semVinculoDireto = empty($item['id_variacao']) && empty($item['codigo_barras']);

                if ($semReferencia && ($forcarProdutoNovo || $semVinculoDireto)) {
                    $validator->errors()->add(
                        "itens.$index.ref",
                        "$label: informe a referencia para cadastrar um produto novo."
                    );
                }

                $categoriaId = $item['id_categoria'] ?? null;
                if (is_numeric($categoriaId) && $categoriasProibidas->has((int) $categoriaId)) {
                    $validator->errors()->add("itens.$index.id_categoria", self::MENSAGEM_CATEGORIA_IMPORTACAO_INVALIDA);
                }

                $variacaoId = $item['id_variacao'] ?? null;
                if (is_numeric($variacaoId) && $variacoesComCategoriaProibida->has((int) $variacaoId)) {
                    $validator->errors()->add("itens.$index.id_categoria", self::MENSAGEM_CATEGORIA_IMPORTACAO_INVALIDA);
                }

                if (isset($item['atributos']) && !is_array($item['atributos'])) {
                    continue;
                }

                $atributos = $this->atributosProdutoImportacao($item);
                $normalizados = [];
                foreach ($atributos as $atributo => $valor) {
                    $nome = trim((string) $atributo);

                    if ($nome === '') {
                        $validator->errors()->add(
                            "itens.$index.atributos",
                            "$label: informe o nome do atributo ou remova a linha incompleta."
                        );
                        continue;
                    }

                    if (mb_strlen($nome) > 100) {
                        $validator->errors()->add(
                            "itens.$index.atributos.$atributo",
                            "$label: o nome do atributo \"$nome\" deve ter no máximo 100 caracteres."
                        );
                    }

                    if (is_array($valor)) {
                        $validator->errors()->add(
                            "itens.$index.atributos.$atributo",
                            "$label: o valor do atributo \"$nome\" deve ser um texto."
                        );
                        continue;
                    }

                    $valorTexto = trim((string) $valor);
                    if ($valorTexto === '') {
                        continue;
                    }

                    if (mb_strlen($valorTexto) > 100) {
                        $validator->errors()->add(
                            "itens.$index.atributos.$atributo",
                            "$label: o valor do atributo \"$nome\" deve ter no máximo 100 caracteres."
                        );
                    }

                    $normalizado = StringHelper::normalizarAtributo($nome);
                    if (isset($normalizados[$normalizado])) {
                        $validator->errors()->add(
                            "itens.$index.atributos.$atributo",
                            "$label: o atributo \"$nome\" está duplicado. Mantenha apenas uma linha para esse atributo."
                        );
                    }
                    $normalizados[$normalizado] = true;
                }
            }
        });

        // Condicional: se for venda, cliente.id é obrigatório
        $validator->sometimes('cliente.id', 'required|numeric|min:1', function ($input) {
            return data_get($input, 'pedido.tipo') === Pedido::TIPO_VENDA;
        });

        if ($validator->fails()) {
            SierraLog::inventory('inventory.order_xml_import.validation_failed', [
                'usuario_id' => Auth::id(),
                'erros' => $validator->errors()->toArray(),
            ], 'warning');
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
            $fornecedorId = $this->toNullableInt($dadosPedido['id_fornecedor'] ?? null);

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

            $totalItens = collect($itens)->sum(
                fn($i) => $this->toDecimal($i['quantidade'] ?? 0)
                    * $this->toDecimal($i['valor'] ?? ($i['preco_unitario'] ?? 0))
            );
            $fluxoManualSemStaging = empty($importacaoId) && !$this->hasValue($request->input('tipo_importacao'));
            $valorTotal = $this->hasValue($dadosPedido['total'] ?? null)
                ? $this->toDecimal($dadosPedido['total'])
                : $totalItens;

            if ($fluxoManualSemStaging && $valorTotal <= 0 && $totalItens > 0) {
                $valorTotal = $totalItens;
            }

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
            $movimentarEstoque = $request->has('movimentar_estoque')
                ? $this->toBoolean($request->input('movimentar_estoque'))
                : true;
            $tiposMovimentacaoPorIndice = [];
            foreach ($itens as $index => $itemMovimentacao) {
                $tiposMovimentacaoPorIndice[$index] = $this->normalizarTipoMovimentacaoItem(
                    $tipo,
                    $itemMovimentacao['movimentacao_estoque_tipo'] ?? null
                );
            }
            $dataEntregaTopLevel = $request->input('data_entrega');
            $dataEntregaPedidoLegado = $dadosPedido['data_entrega'] ?? null;
            $dataEntrega = DateNormalizer::normalizeDate(
                $dataEntregaTopLevel ?? $dataEntregaPedidoLegado,
                'data_entrega'
            );

            if (!$previsaoTipo) {
                $previsaoTipo = 'DIAS_UTEIS';
                $diasUteisPrevistos = self::PRAZO_IMPORTACAO_PADRAO_DIAS_UTEIS;
                $diasCorridosPrevistos = null;
                $dataPrevista = null;
            } elseif ($previsaoTipo === 'DIAS_UTEIS' && $diasUteisPrevistos === null) {
                $diasUteisPrevistos = self::PRAZO_IMPORTACAO_PADRAO_DIAS_UTEIS;
            }

            if ($entregue && !$dataEntrega) {
                throw ValidationException::withMessages([
                    'data_entrega' => ['Informe a data de entrega quando o pedido já foi entregue.'],
                ]);
            }

            if ($movimentarEstoque) {
                $itensSemDeposito = collect($itens)
                    ->filter(fn ($item) => empty($item['id_deposito']))
                    ->keys()
                    ->map(fn ($index) => 'Item ' . ((int) $index + 1))
                    ->values()
                    ->all();

                if ($itensSemDeposito !== []) {
                    throw ValidationException::withMessages([
                        'itens' => ['Informe deposito para movimentar estoque dos itens importados: ' . implode(', ', $itensSemDeposito) . '.'],
                    ]);
                }
            }

            if ($tipo === Pedido::TIPO_VENDA && $entregue && $movimentarEstoque) {
                $itensSemSaida = collect($tiposMovimentacaoPorIndice)
                    ->filter(fn ($tipoMovimentacao) => $tipoMovimentacao !== self::MOVIMENTACAO_SAIDA)
                    ->keys()
                    ->map(fn ($index) => 'Item ' . ((int) $index + 1))
                    ->values()
                    ->all();

                if ($itensSemSaida !== []) {
                    throw ValidationException::withMessages([
                        'itens' => [
                            $this->mensagemVendaEntregueItensSemSaida($itensSemSaida),
                        ],
                    ]);
                }
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
                'id_fornecedor' => $fornecedorId,
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

            if ($movimentarEstoque && ($entregue || $tipo === Pedido::TIPO_REPOSICAO)) {
                PedidoStatusHistorico::create([
                    'pedido_id'   => $pedido->id,
                    'status'      => $tipo === Pedido::TIPO_REPOSICAO
                        ? PedidoStatus::ENTREGA_ESTOQUE
                        : PedidoStatus::ENTREGA_CLIENTE,
                    'data_status' => $dataEntrega?->toDateTimeString(),
                    'usuario_id'  => $usuario->id,
                    'observacoes' => 'Status aplicado na confirmação da importação XML.',
                ]);
            }

            $pedidoItensCriados = collect();

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
                    SierraLog::inventory('inventory.order_xml_import.item_normalized', [
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
                                $this->mensagemReferenciaAmbiguaImportacao($item, $index),
                            ],
                        ]);
                    }
                }

                if (!$variacao) {
                    if (!$this->hasValue($item['ref'] ?? null)) {
                        throw ValidationException::withMessages([
                            "itens.$index.ref" => [
                                'Produto ' . ($index + 1) . ': informe a referencia para cadastrar um produto novo.',
                            ],
                        ]);
                    }

                    $produto = Produto::firstOrCreate([
                        'nome'         => $item['nome'],
                        'id_categoria' => $item['id_categoria'],
                    ], [
                        'id_fornecedor' => $fornecedorId,
                    ]);

                    $variacao = ProdutoVariacao::create([
                        'produto_id' => $produto->id,
                        'referencia' => $item['ref'] ?? null,
                        'sku_interno' => $item['sku_interno'] ?? null,
                        'nome'       => $item['nome'],
                        'preco'      => $valorUnit,
                        'custo'      => $custoUnit,
                    ]);

                    foreach ($this->atributosProdutoImportacao($item) as $atrib => $valor) {
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

                $pedidoItem = PedidoItem::create([
                    'id_pedido'      => $pedido->id,
                    'id_variacao'    => $variacao->id,
                    'quantidade'     => $quantidade,
                    'preco_unitario' => $valorUnit,
                    'custo_unitario' => $custoUnit,
                    'subtotal'       => (float)$quantidade * (float)$valorUnit,
                    'id_deposito'    => $item['id_deposito'] ?? null,
                    'observacoes'    => $item['atributos_nfe']['observacao'] ?? $item['atributos']['observacao'] ?? null,
                ]);
                $pedidoItensCriados->push([
                    'item' => $pedidoItem,
                    'movimentacao_estoque_tipo' => $tiposMovimentacaoPorIndice[$index] ?? self::MOVIMENTACAO_ENTRADA,
                ]);

                PedidoImportacaoItem::create([
                    'pedido_importacao_id' => $importacaoId ? (int) $importacaoId : null,
                    'pedido_id' => $pedido->id,
                    'pedido_item_id' => $pedidoItem->id,
                    'produto_id' => $variacao->produto_id,
                    'produto_variacao_id' => $variacao->id,
                    'acao' => $forcarProdutoNovo ? 'criado' : 'vinculado',
                    'dados_importados_json' => $item,
                    'dados_confirmados_json' => [
                        'pedido_item_id' => $pedidoItem->id,
                        'produto_id' => $variacao->produto_id,
                        'produto_variacao_id' => $variacao->id,
                        'nome_produto' => $variacao->produto?->nome,
                        'nome_completo' => $variacao->nome_completo,
                        'referencia' => $variacao->referencia,
                        'sku_interno' => $variacao->sku_interno,
                        'quantidade' => $quantidade,
                        'preco_unitario' => $valorUnit,
                        'custo_unitario' => $custoUnit,
                        'id_deposito' => $item['id_deposito'] ?? null,
                        'atributos_nfe' => $item['atributos_nfe'] ?? null,
                    ],
                ]);
            }

            if (isset($importacao)) {
                $importacao->update([
                    'status' => 'confirmado',
                    'pedido_id' => $pedido->id,
                    'numero_externo' => $numeroExterno ?: $importacao->numero_externo,
                ]);
            }

            SierraLog::inventory('inventory.order_xml_import.order_confirmed', [
                'usuario_id' => $usuario->id,
                'entity_type' => 'pedido',
                'entity_id' => $pedido->id,
                'batch_id' => $importacaoId,
                'itens_total' => count($itens),
            ]);

            $this->aplicarMovimentacoesImportacao(
                $pedido,
                $pedidoItensCriados,
                $movimentarEstoque,
                $entregue,
                $usuario->id
            );

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
            SierraLog::inventory('inventory.order_xml_import.normalization_failed', [
                'usuario_id' => Auth::id(),
                'erros' => $e->errors(),
                'exception' => $e,
            ], 'warning');
            throw $e;
        } catch (\Throwable $e) {
            SierraLog::inventory('inventory.order_xml_import.confirmation_failed', [
                'usuario_id' => Auth::id(),
                'entity_type' => 'pedido_importacao',
                'entity_id' => $request->input('importacao_id'),
                'exception' => $e,
            ], 'error');

            if (
                str_contains($e->getMessage(), 'SQLSTATE[22001]')
                && str_contains($e->getMessage(), 'produto_variacao_atributos')
            ) {
                throw ValidationException::withMessages([
                    'itens' => [
                        'Um atributo do produto está maior que o permitido. Revise os atributos dos produtos novos antes de salvar.',
                    ],
                ]);
            }

            throw $e;
        } finally {
            SierraLog::inventory('inventory.order_xml_import.confirmation_finished', [
                'usuario_id' => Auth::id(),
                'entity_type' => 'pedido_importacao',
                'entity_id' => $request->input('importacao_id'),
            ]);
        }
    }

    private function normalizarTipoMovimentacaoItem(string $tipoPedido, mixed $tipoMovimentacao): string
    {
        if ($tipoPedido === Pedido::TIPO_REPOSICAO) {
            return self::MOVIMENTACAO_ENTRADA;
        }

        $normalizado = strtolower(trim((string) $tipoMovimentacao));

        return $normalizado === self::MOVIMENTACAO_SAIDA
            ? self::MOVIMENTACAO_SAIDA
            : self::MOVIMENTACAO_ENTRADA;
    }

    /**
     * @param Collection<int,array{item:PedidoItem,movimentacao_estoque_tipo:string}> $pedidoItens
     */
    private function aplicarMovimentacoesImportacao(
        Pedido $pedido,
        Collection $pedidoItens,
        bool $movimentarEstoque,
        bool $entregue,
        ?int $usuarioId
    ): void {
        $entregas = app(EntregaProdutoService::class);
        $entregaItens = $entregas->criarDemandaPedido($pedido, $usuarioId, false)
            ->keyBy('pedido_item_id');

        if (!$movimentarEstoque) {
            return;
        }

        $movimentacoes = app(EstoqueMovimentacaoService::class);

        foreach ($pedidoItens as $registro) {
            /** @var PedidoItem $pedidoItem */
            $pedidoItem = $registro['item'];
            $tipoMovimentacao = $this->normalizarTipoMovimentacaoItem(
                (string) $pedido->tipo,
                $registro['movimentacao_estoque_tipo'] ?? null
            );
            $entrega = $entregaItens->get($pedidoItem->id);

            if (!$entrega) {
                continue;
            }

            $depositoId = $pedidoItem->id_deposito ? (int) $pedidoItem->id_deposito : null;
            $quantidade = (int) $pedidoItem->quantidade;

            if ($pedido->isReposicao()) {
                $entregas->receberItem(
                    $entrega,
                    $depositoId,
                    $quantidade,
                    $usuarioId,
                    'Recebimento de reposicao importada.',
                    "importacao-pedido:{$pedidoItem->id}:entrada"
                );
                continue;
            }

            if ($tipoMovimentacao === self::MOVIMENTACAO_SAIDA) {
                $entregaAtualizada = $entregas->expedirItem(
                    $entrega,
                    $depositoId,
                    $quantidade,
                    $usuarioId,
                    'Saida de estoque registrada na importacao do pedido.',
                    ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                    "importacao-pedido:{$pedidoItem->id}:saida"
                );

                if ($entregue) {
                    $entregas->entregarItem(
                        $entregaAtualizada,
                        $quantidade,
                        $usuarioId,
                        'Entrega ao cliente registrada na importacao do pedido.',
                        "importacao-pedido:{$pedidoItem->id}:entrega"
                    );
                }

                continue;
            }

            $movimentacoes->registrarMovimentacaoManual([
                'id_variacao' => (int) $pedidoItem->id_variacao,
                'id_deposito_origem' => null,
                'id_deposito_destino' => $depositoId,
                'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
                'quantidade' => $quantidade,
                'observacao' => 'Entrada de fabrica registrada na importacao do pedido.',
                'data_movimentacao' => now(),
                'ref_type' => 'pedido',
                'ref_id' => $pedido->id,
                'pedido_id' => $pedido->id,
                'pedido_item_id' => $pedidoItem->id,
            ], $usuarioId);

            $entregas->reservarItem(
                $entrega,
                $depositoId,
                $quantidade,
                $usuarioId,
                'Reserva criada apos entrada de fabrica importada.',
                "importacao-pedido:{$pedidoItem->id}:reserva"
            );
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

    private function atributosProdutoImportacao(array $item): array
    {
        $atributos = $item['atributos'] ?? [];
        if (!is_array($atributos)) {
            return [];
        }

        return array_filter(
            $atributos,
            fn ($valor, $chave) => !in_array(strtolower(trim((string) $chave)), self::ATRIBUTOS_FISCAIS_NFE, true),
            ARRAY_FILTER_USE_BOTH
        );
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
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
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
     * Mescla itens extraídos da importação XML com itens já cadastrados.
     *
     * - Enriquece com nome_completo
     * - Envia atributos da variação
     * - Envia dimensões do produto (largura, profundidade, altura) em "fixos"
     *
     * @param array $itens
     * @return array
     */
    public function mesclarItensComVariacoes(array $itens, ?string $estrategiaVinculo = null, array $opcoes = []): array
    {
        $categoriaSugerida = $this->normalizarCategoriaSugerida($opcoes['categoria_sugerida'] ?? null);

        return collect($itens)->values()->map(function ($item, int $index) use ($categoriaSugerida) {
            $linha = $this->normalizarLinhaItem($item['linha'] ?? null, $index + 1);

            $ref = isset($item['codigo']) && trim((string) $item['codigo']) !== ''
                ? trim((string) $item['codigo'])
                : (isset($item['ref']) ? trim((string) $item['ref']) : null);
            $codigoBarras = isset($item['codigo_barras']) ? trim((string) $item['codigo_barras']) : null;

            if (!$ref && !$codigoBarras) {
                $item['linha'] = $linha;
                return $this->aplicarCategoriaSugerida($item, $categoriaSugerida);
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

            // 2) Para identificadores gerais, usa a mesma regra da confirmação:
            //    se houver mais de uma variação candidata, o front precisa escolher.
            if ($ref) {
                $variacoesPorIdentificador = ProdutoVariacao::with(['produto.categoria', 'atributos'])
                    ->where(function ($query) use ($ref) {
                        $this->aplicarBuscaPorIdentificador($query, $ref);
                    })
                    ->get();

                $variacoesEncontradas = $this->variacoesParaListaPreview($variacoesPorIdentificador);

                if ($variacoesPorIdentificador->count() === 1) {
                    $variacaoUnica = $variacoesPorIdentificador->first();
                    $itemMapeado = $this->mapearItemComVariacaoEncontrada($item, $linha, $ref, $variacaoUnica);

                    // Contrato: identificador deve retornar TODAS as variações relacionadas ao valor informado.
                    // Mesmo quando há apenas 1, entregamos a lista para o front manter estado consistente.
                    return array_merge($itemMapeado, [
                        'variacoes_encontradas' => $variacoesEncontradas,
                    ]);
                }

                if ($variacoesPorIdentificador->count() > 1) {
                    $categoriaId = $item['id_categoria'] ?? null;
                    $categoriaNome = $item['categoria'] ?? $this->categoriaNome($categoriaId);

                    $itemMapeado = array_merge($item, [
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
                        "variacoes_encontradas" => $variacoesEncontradas,
                    ]);

                    return $this->aplicarCategoriaSugerida($itemMapeado, $categoriaSugerida);
                }
            }

            // Produto não encontrado: preservar dados da importação.
            $categoriaId = $item['id_categoria'] ?? null;

            $itemMapeado = array_merge($item, [
                "linha"         => $linha,
                "ref"           => $ref,
                "produto_id"    => null,
                "id_variacao"   => null,
                "variacao_nome" => null,
                "id_categoria"  => $categoriaId,
                "categoria"  => $item['categoria'] ?? $this->categoriaNome($categoriaId),
                "atributos"     => $item['atributos'] ?? [],
                "fixos"         => $item['fixos'] ?? [],
            ]);

            return $this->aplicarCategoriaSugerida($itemMapeado, $categoriaSugerida);
        })->toArray();
    }

    private function normalizarCategoriaSugerida(mixed $categoria): ?array
    {
        if (!is_array($categoria)) {
            return null;
        }

        $id = $categoria['id'] ?? $categoria['id_categoria'] ?? null;
        $nome = trim((string) ($categoria['nome'] ?? $categoria['categoria'] ?? ''));

        if (!is_numeric($id) || (int) $id <= 0 || $nome === '') {
            return null;
        }

        return [
            'id' => (int) $id,
            'nome' => $nome,
        ];
    }

    private function aplicarCategoriaSugerida(array $item, ?array $categoriaSugerida): array
    {
        if ($categoriaSugerida === null) {
            return $item;
        }

        $categoriaId = $item['id_categoria'] ?? null;
        $categoriaNome = trim((string) ($item['categoria'] ?? ''));

        if ((is_numeric($categoriaId) && (int) $categoriaId > 0) || $categoriaNome !== '') {
            return $item;
        }

        return array_merge($item, [
            'id_categoria' => $categoriaSugerida['id'],
            'categoria' => $categoriaSugerida['nome'],
        ]);
    }

    private function mapearItemComVariacaoEncontrada(array $item, int $linha, ?string $ref, ProdutoVariacao $variacao): array
    {
        $produto = $variacao->produto;

        $atributosVariacao = $variacao->relationLoaded('atributos')
            ? $variacao->atributos->mapWithKeys(fn($attr) => [$attr->atributo => $attr->valor])->toArray()
            : [];

        // Atributos vindos da importação (se houver); db sobrescreve o que vier errado.
        $atributosImportacao = $item['atributos'] ?? [];
        $atributosFinal = array_merge($atributosImportacao, $atributosVariacao);

        // Dimensões vindas do produto
        $fixosDb = [
            'largura' => $produto?->largura,
            'profundidade' => $produto?->profundidade,
            'altura' => $produto?->altura,
        ];

        $fixosImportacao = $item['fixos'] ?? [];
        $fixosFinal = array_merge(
            $fixosImportacao,
            array_filter($fixosDb, fn($v) => !is_null($v))
        );

        $categoriaId = $produto?->id_categoria;
        $categoriaNome = $produto?->categoria?->nome ?? $this->categoriaNome($categoriaId);

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

    private function mensagemReferenciaAmbiguaImportacao(array $item, int $index): string
    {
        $rotulo = $this->rotuloItemImportacao($item, $index);
        $referencia = isset($item['ref']) ? trim((string) $item['ref']) : '';

        if ($referencia !== '') {
            $rotulo .= " (Ref. {$referencia})";
        }

        return "{$rotulo}: a referência corresponde a múltiplas variações. Selecione a variação correta na tela de importação.";
    }

    private function rotuloItemImportacao(array $item, int $index): string
    {
        $prefixo = 'Produto ' . ($index + 1);

        foreach (['nome_completo', 'nome', 'descricao'] as $campo) {
            $valor = $this->normalizarTextoMensagemImportacao($item[$campo] ?? null);

            if ($valor !== '') {
                return "{$prefixo}: {$valor}";
            }
        }

        return $prefixo;
    }

    /**
     * @param list<string> $itensSemSaida
     */
    private function mensagemVendaEntregueItensSemSaida(array $itensSemSaida): string
    {
        $total = count($itensSemSaida);

        if ($total === 1) {
            return 'Pedido entregue: este item precisa estar como Saída para baixar o estoque. Altere para Saída ou use "Salvar sem movimentar". Item pendente: ' . $itensSemSaida[0] . '.';
        }

        $itensVisiveis = array_slice($itensSemSaida, 0, 3);
        $restantes = $total - count($itensVisiveis);
        $resumoItens = implode(', ', $itensVisiveis);

        if ($restantes > 0) {
            $resumoItens .= " e mais {$restantes}";
        }

        return "Pedido entregue: {$total} itens precisam estar como Saída para baixar o estoque. Use \"Aplicar a todos > Saída\" ou \"Salvar sem movimentar\". Itens pendentes: {$resumoItens}.";
    }

    private function normalizarTextoMensagemImportacao(mixed $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $valor));
    }

    private function categoriaNome(mixed $categoriaId): ?string
    {
        if (!is_numeric($categoriaId)) {
            return null;
        }

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
