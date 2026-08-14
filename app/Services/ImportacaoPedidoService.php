<?php

namespace App\Services;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\EstrategiaVinculoImportacao;
use App\Enums\PedidoStatus;
use App\Enums\TipoImportacao;
use App\Helpers\AuthHelper;
use App\Helpers\StringHelper;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\PedidoImportacaoItem;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\ProdutoVariacaoCodigoHistorico;
use App\Support\Dates\DateNormalizer;
use App\Support\Logging\SierraLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

    private const ATRIBUTOS_IDENTIDADE_VARIACAO = [
        'madeira',
        'tecido_1',
        'metal_vidro',
        'tecido_2',
    ];

    private const METADADOS_PREVIEW_ITEM = [
        'atributos_detectados',
        'atributos_detectados_lista',
        'revisao_atributos_variacao',
        'nome_detectado',
        'vinculo_sugerido',
        'variacoes_encontradas',
        'compatibilidade',
    ];

    /**
     * Confirma os dados da importação de um pedido, salvando no banco.
     *
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
            'pedido.tipo' => 'required|in:venda,reposicao',
            'importacao_id' => 'nullable|integer|exists:pedido_importacoes,id',
            'tipo_importacao' => 'nullable|in:'.implode(',', TipoImportacao::valores()),
            'idempotency_key' => ['nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:-]+$/'],

            'cliente.id' => 'nullable|integer|min:1|exists:clientes,id',

            'pedido.numero_externo' => 'required|string|max:50',
            'pedido.id_usuario' => 'nullable|integer|exists:acesso_usuarios,id',
            'pedido.id_vendedor' => 'nullable|integer|exists:acesso_usuarios,id',
            'pedido.id_fornecedor' => 'nullable|integer|exists:fornecedores,id',
            'pedido.total' => 'nullable|numeric',
            'pedido.observacoes' => 'nullable|string',
            'pedido.data_pedido' => 'nullable|string',
            'pedido.data_inclusao' => 'nullable|string',
            'pedido.data_entrega' => 'nullable|string',
            'pedido.entregue' => 'nullable|boolean',
            'pedido.previsao_tipo' => 'nullable|in:DATA,DIAS_UTEIS,DIAS_CORRIDOS',
            'pedido.data_prevista' => 'nullable|string',
            'pedido.dias_uteis_previstos' => 'nullable|integer|min:0|max:3650',
            'pedido.dias_corridos_previstos' => 'nullable|integer|min:0|max:3650',

            'entregue' => 'nullable|boolean',
            'movimentar_estoque' => 'nullable|boolean',
            'data_entrega' => 'nullable|string',
            'previsao_tipo' => 'nullable|in:DATA,DIAS_UTEIS,DIAS_CORRIDOS',
            'data_prevista' => 'nullable|string',
            'dias_uteis_previstos' => 'nullable|integer|min:0|max:3650',
            'dias_corridos_previstos' => 'nullable|integer|min:0|max:3650',

            'itens' => 'required|array|min:1',
            'itens.*.nome' => 'required|string|max:255',
            'itens.*.ref' => 'nullable|string|max:100',
            'itens.*.codigo_origem' => 'nullable|string|max:120',
            'itens.*.sku_interno' => 'nullable|string|max:120',
            'itens.*.quantidade' => 'required|integer|min:1',
            'itens.*.valor' => 'required|numeric|min:0|max:99999999.99',
            'itens.*.preco_unitario' => 'nullable|numeric|min:0|max:99999999.99',
            'itens.*.custo_unitario' => 'nullable|numeric|min:0|max:99999999.99',
            'itens.*.id_categoria' => 'required|integer|exists:categorias,id',
            'itens.*.id_deposito' => 'nullable|integer|exists:depositos,id',
            'itens.*.deposito_recebimento_id' => 'nullable|integer|exists:depositos,id',
            'itens.*.antecipacoes' => 'nullable|array',
            'itens.*.antecipacoes.*.deposito_id' => 'required|integer|exists:depositos,id',
            'itens.*.antecipacoes.*.quantidade' => 'required|integer|min:1',
            'itens.*.movimentacao_estoque_tipo' => 'nullable|in:entrada,saida',
            'itens.*.atributos' => 'nullable|array',
            'itens.*.atributos.*' => 'nullable',
            'itens.*.atributos_lista' => 'nullable|array',
            'itens.*.atributos_lista.*' => 'array',
            'itens.*.atributos_lista.*.atributo' => 'nullable',
            'itens.*.atributos_lista.*.valor' => 'nullable',
            'itens.*.atributos_nfe' => 'nullable|array',
            'itens.*.atributos_detectados_lista' => 'nullable|array',
            'itens.*.atributos_detectados_lista.*' => 'array',
            'itens.*.atributos_detectados_lista.*.atributo' => 'nullable|string|max:100',
            'itens.*.atributos_detectados_lista.*.valor' => 'nullable|string|max:100',
            'itens.*.decisao_atributos_variacao' => 'nullable|in:adicionar,manter',
            'estrategia_vinculo' => 'nullable|in:'.implode(',', EstrategiaVinculoImportacao::valores()),
            'itens.*.forcar_produto_novo' => 'nullable|boolean',
        ], [
            'itens.required' => 'Adicione ao menos um item ao pedido antes de confirmar.',
            'itens.min' => 'Adicione ao menos um item ao pedido (inserção manual) antes de confirmar.',
            'pedido.numero_externo.required' => 'Informe o número do pedido antes de confirmar.',
            'pedido.numero_externo.max' => 'Número do pedido deve ter no máximo 50 caracteres.',
            'idempotency_key.required' => 'Informe a chave de idempotencia para confirmar o pedido manual.',
            'cliente.id.required' => 'Selecione um cliente para confirmar o pedido manual de venda.',
            'cliente.id.exists' => 'O cliente selecionado não foi encontrado.',
            'itens.*.nome.required' => 'Informe o nome do produto.',
            'itens.*.nome.max' => 'O nome do produto deve ter no máximo 255 caracteres.',
            'itens.*.ref.max' => 'A referência do produto deve ter no máximo 100 caracteres.',
            'itens.*.codigo_origem.max' => 'O código original do produto deve ter no máximo 120 caracteres.',
            'itens.*.sku_interno.max' => 'O SKU interno deve ter no máximo 120 caracteres.',
            'itens.*.quantidade.integer' => 'A quantidade deve ser um número inteiro maior que zero.',
            'itens.*.quantidade.min' => 'A quantidade deve ser um número inteiro maior que zero.',
            'itens.*.valor.max' => 'O preço de venda deve ser no máximo R$ 99.999.999,99.',
            'itens.*.preco_unitario.max' => 'O preço unitário deve ser no máximo R$ 99.999.999,99.',
            'itens.*.custo_unitario.max' => 'O custo unitário deve ser no máximo R$ 99.999.999,99.',
            'itens.*.id_categoria.required' => 'Selecione uma categoria para todos os produtos.',
            'itens.*.atributos.array' => 'Os atributos do produto devem ser enviados em formato válido.',
            'itens.*.atributos_lista.array' => 'Os atributos do produto devem ser enviados em formato válido.',
            'itens.*.atributos_lista.*.array' => 'Cada atributo do produto deve conter nome e valor.',
        ]);

        $validator->after(function ($validator) use ($request) {
            $itens = $request->input('itens', []);
            if (! is_array($itens)) {
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

            $variacoesParaRevisao = $variacaoIds->isEmpty()
                ? collect()
                : ProdutoVariacao::query()
                    ->with('atributos')
                    ->whereIn('id', $variacaoIds)
                    ->get()
                    ->keyBy('id');

            foreach ($itens as $index => $item) {
                $label = 'Produto '.($index + 1);
                $forcarProdutoNovo = $this->itemDeveForcarProdutoNovo($request, $item);
                $semReferencia = ! $this->hasValue($item['ref'] ?? null);
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

                if (! $forcarProdutoNovo && is_numeric($variacaoId)) {
                    $variacaoRevisada = $variacoesParaRevisao->get((int) $variacaoId);
                    if ($variacaoRevisada) {
                        $revisao = $this->analisarRevisaoAtributosVariacao($item, $variacaoRevisada);
                        $decisao = trim((string) ($item['decisao_atributos_variacao'] ?? ''));

                        if ($revisao['requerida'] && ! in_array($decisao, ['adicionar', 'manter'], true)) {
                            $validator->errors()->add(
                                "itens.$index.decisao_atributos_variacao",
                                "$label: escolha adicionar os atributos detectados ou manter o cadastro atual."
                            );
                        }

                        if ($revisao['requerida'] && $decisao === 'adicionar' && $revisao['conflitos'] !== []) {
                            $validator->errors()->add(
                                "itens.$index.decisao_atributos_variacao",
                                "$label: existem atributos conflitantes no cadastro. Mantenha o cadastro, selecione outra variação ou cadastre uma nova."
                            );
                        }
                    }
                }

                $quantidade = (int) ($item['quantidade'] ?? 0);
                $quantidadeAntecipada = collect($item['antecipacoes'] ?? [])
                    ->sum(fn ($antecipacao) => (int) ($antecipacao['quantidade'] ?? 0));

                if ($quantidadeAntecipada > $quantidade) {
                    $validator->errors()->add(
                        "itens.$index.antecipacoes",
                        "$label: a quantidade antecipada nao pode exceder a quantidade do item."
                    );
                }

                if (data_get($request, 'pedido.tipo') === Pedido::TIPO_REPOSICAO && $quantidadeAntecipada > 0) {
                    $validator->errors()->add(
                        "itens.$index.antecipacoes",
                        "$label: antecipacao com estoque atual e permitida apenas em pedidos de venda."
                    );
                }

                if (
                    (isset($item['atributos_lista']) && ! is_array($item['atributos_lista']))
                    || (! array_key_exists('atributos_lista', $item)
                        && isset($item['atributos'])
                        && ! is_array($item['atributos']))
                ) {
                    continue;
                }

                $usaLista = array_key_exists('atributos_lista', $item);
                $atributos = $this->atributosListaProdutoImportacao($item);
                $normalizados = [];
                foreach ($atributos as $indiceAtributo => $atributo) {
                    $nome = trim((string) ($atributo['atributo'] ?? ''));
                    $valor = $atributo['valor'] ?? null;
                    $caminhoNome = $usaLista
                        ? "itens.$index.atributos_lista.$indiceAtributo.atributo"
                        : "itens.$index.atributos.$nome";
                    $caminhoValor = $usaLista
                        ? "itens.$index.atributos_lista.$indiceAtributo.valor"
                        : "itens.$index.atributos.$nome";

                    if ($nome === '') {
                        $validator->errors()->add(
                            $usaLista ? $caminhoNome : "itens.$index.atributos",
                            "$label: informe o nome do atributo ou remova a linha incompleta."
                        );

                        continue;
                    }

                    if (mb_strlen($nome) > 100) {
                        $validator->errors()->add(
                            $caminhoNome,
                            "$label: o nome do atributo \"$nome\" deve ter no máximo 100 caracteres."
                        );
                    }

                    if (is_array($valor) || is_object($valor)) {
                        $validator->errors()->add(
                            $caminhoValor,
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
                            $caminhoValor,
                            "$label: o valor do atributo \"$nome\" deve ter no máximo 100 caracteres."
                        );
                    }

                    $parNormalizado = StringHelper::normalizarAtributo($nome)
                        .'|'.$this->normalizarValorAtributoComparacao($valorTexto);
                    if (isset($normalizados[$parNormalizado])) {
                        $validator->errors()->add(
                            $caminhoValor,
                            "$label: o atributo \"$nome\" com o valor \"$valorTexto\" está duplicado. "
                            .'Mantenha apenas uma ocorrência desse par.'
                        );
                    }
                    $normalizados[$parNormalizado] = true;
                }
            }
        });

        // Condicional: se for venda, cliente.id é obrigatório
        $validator->sometimes('cliente.id', 'required|numeric|min:1', function ($input) {
            return data_get($input, 'pedido.tipo') === Pedido::TIPO_VENDA;
        });
        $validator->sometimes('idempotency_key', 'required', function ($input) {
            return empty($input->importacao_id) && empty($input->tipo_importacao);
        });

        $validator->sometimes('cliente.id', 'required', function ($input) {
            return empty($input->importacao_id)
                && empty($input->tipo_importacao)
                && data_get($input, 'pedido.tipo') === Pedido::TIPO_VENDA;
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
                $usuario = Auth::user();
                $dadosCliente = (array) $request->cliente;
                $dadosPedido = (array) $request->pedido;
                $itens = (array) $request->itens;
                $importacaoId = $request->input('importacao_id');
                $fluxoManualSemStaging = empty($importacaoId) && ! $this->hasValue($request->input('tipo_importacao'));

                $tipo = $dadosPedido['tipo'] ?? Pedido::TIPO_VENDA;
                $fornecedorId = $this->toNullableInt($dadosPedido['id_fornecedor'] ?? null);
                $vendedorSelecionadoId = $this->toNullableInt(
                    $dadosPedido['id_usuario'] ?? ($dadosPedido['id_vendedor'] ?? null)
                );
                $vendedorFinalId = $vendedorSelecionadoId ?? (int) $usuario->id;

                if ($vendedorSelecionadoId !== null && $vendedorSelecionadoId !== (int) $usuario->id) {
                    if (! AuthHelper::podeSelecionarVendedorPedido()) {
                        throw ValidationException::withMessages([
                            'pedido.id_usuario' => ['Sem permissao para selecionar vendedor.'],
                        ]);
                    }
                }

                if ($fluxoManualSemStaging) {
                    $idempotencyKey = trim((string) $request->input('idempotency_key'));
                    $arquivoHash = hash('sha256', "manual:{$usuario->id}:{$idempotencyKey}");
                    PedidoImportacao::query()->insertOrIgnore([
                        'arquivo_nome' => $tipo === Pedido::TIPO_VENDA ? 'venda-manual' : 'reposicao-manual',
                        'arquivo_hash' => $arquivoHash,
                        'numero_externo' => $dadosPedido['numero_externo'] ?? null,
                        'usuario_id' => $usuario->id,
                        'status' => 'extraido',
                        'dados_json' => json_encode(['origem' => 'manual'], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $importacao = PedidoImportacao::query()
                        ->where('arquivo_hash', $arquivoHash)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $importacaoId = (int) $importacao->id;
                }

                if ($importacaoId) {
                    /** @var PedidoImportacao $importacao */
                    $importacao ??= PedidoImportacao::query()
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

                    if (! empty($dadosCliente['id'])) {
                        $cliente = Cliente::findOrFail((int) data_get($request->cliente, 'id'));
                    } else {
                        $cliente = Cliente::firstOrCreate(
                            ['documento' => $dadosCliente['documento']],
                            [
                                'nome' => $dadosCliente['nome'] ?? 'Cliente',
                                'email' => $dadosCliente['email'] ?? null,
                                'telefone' => $dadosCliente['telefone'] ?? null,
                                'endereco' => $dadosCliente['endereco'] ?? null,
                            ]
                        );
                    }

                    $clienteId = $cliente->id;
                }

                $totalItens = collect($itens)->sum(
                    fn ($i) => $this->toDecimal($i['quantidade'] ?? 0)
                        * $this->toDecimal($i['valor'] ?? ($i['preco_unitario'] ?? 0))
                );
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
                $fluxoV2 = (bool) config('pedidos.fluxo_operacional_v2_enabled');
                $movimentarEstoque = ! $fluxoV2 && $request->has('movimentar_estoque')
                    ? $this->toBoolean($request->input('movimentar_estoque'))
                    : false;
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

                if (! $previsaoTipo) {
                    $previsaoTipo = 'DIAS_UTEIS';
                    $diasUteisPrevistos = self::PRAZO_IMPORTACAO_PADRAO_DIAS_UTEIS;
                    $diasCorridosPrevistos = null;
                    $dataPrevista = null;
                } elseif ($previsaoTipo === 'DIAS_UTEIS' && $diasUteisPrevistos === null) {
                    $diasUteisPrevistos = self::PRAZO_IMPORTACAO_PADRAO_DIAS_UTEIS;
                }

                if ($entregue && ! $dataEntrega) {
                    throw ValidationException::withMessages([
                        'data_entrega' => ['Informe a data de entrega quando o pedido já foi entregue.'],
                    ]);
                }

                if ($movimentarEstoque) {
                    $itensSemDeposito = collect($itens)
                        ->filter(fn ($item) => empty($item['deposito_recebimento_id'] ?? $item['id_deposito'] ?? null))
                        ->keys()
                        ->map(fn ($index) => 'Item '.((int) $index + 1))
                        ->values()
                        ->all();

                    if ($itensSemDeposito !== []) {
                        throw ValidationException::withMessages([
                            'itens' => ['Informe deposito para movimentar estoque dos itens importados: '.implode(', ', $itensSemDeposito).'.'],
                        ]);
                    }
                }

                if ($tipo === Pedido::TIPO_VENDA && $entregue && $movimentarEstoque) {
                    $itensSemSaida = collect($tiposMovimentacaoPorIndice)
                        ->filter(fn ($tipoMovimentacao) => $tipoMovimentacao !== self::MOVIMENTACAO_SAIDA)
                        ->keys()
                        ->map(fn ($index) => 'Item '.((int) $index + 1))
                        ->values()
                        ->all();

                    if ($itensSemSaida !== []) {
                        throw ValidationException::withMessages([
                            'itens' => [$this->mensagemVendaEntregueItensSemSaida($itensSemSaida)],
                        ]);
                    }
                }

                if ($previsaoTipo === 'DATA' && ! $dataPrevista) {
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
                    'tipo' => $tipo,
                    'origem_abastecimento' => Pedido::ORIGEM_ABASTECIMENTO_FABRICA,
                    'id_cliente' => $clienteId,
                    'id_usuario' => $vendedorFinalId,
                    'id_parceiro' => $dadosPedido['id_parceiro'] ?? null,
                    'id_fornecedor' => $fornecedorId,
                    'numero_externo' => $numeroExterno ?: null,
                    'data_pedido' => $dataBasePedido->toDateTimeString(),
                    'valor_total' => $valorTotal,
                    'observacoes' => $dadosPedido['observacoes'] ?? null,
                ];

                if ($entregaPrevista) {
                    $pedidoPayload['data_limite_entrega'] = $entregaPrevista->toDateString();
                }

                if ($previsaoTipo === 'DIAS_UTEIS' && $diasUteisPrevistos !== null) {
                    $pedidoPayload['prazo_dias_uteis'] = $diasUteisPrevistos;
                }

                $pedido = Pedido::create($pedidoPayload);

                PedidoStatusHistorico::create([
                    'pedido_id' => $pedido->id,
                    'status' => PedidoStatus::PEDIDO_CRIADO,
                    'data_status' => $dataBasePedido->toDateTimeString(),
                    'usuario_id' => $usuario->id,
                ]);

                if (! $fluxoV2 && $movimentarEstoque && ($entregue || $tipo === Pedido::TIPO_REPOSICAO)) {
                    $dataStatusMovimentacao = $dataEntrega ?? $dataBasePedido;

                    PedidoStatusHistorico::create([
                        'pedido_id' => $pedido->id,
                        'status' => $tipo === Pedido::TIPO_REPOSICAO
                            ? PedidoStatus::ENTREGA_ESTOQUE
                            : PedidoStatus::ENTREGA_CLIENTE,
                        'data_status' => $dataStatusMovimentacao->toDateTimeString(),
                        'usuario_id' => $usuario->id,
                        'observacoes' => 'Status aplicado na confirmacao da importacao XML (fluxo legado).',
                    ]);
                }

                $pedidoItensCriados = collect();

                foreach ($itens as $index => $item) {
                    $item = $this->normalizarContratoAtributosItem((array) $item);
                    $item['nome'] = $this->normalizarNomeItem($item['nome'] ?? '');
                    $item['ref'] = isset($item['ref']) ? trim((string) $item['ref']) : null;
                    $item['id_deposito'] = $item['deposito_recebimento_id'] ?? ($item['id_deposito'] ?? null);

                    $quantidade = $this->toDecimal($item['quantidade'] ?? 0);
                    $precoUnitarioFonte = $item['preco_unitario'] ?? ($item['preco'] ?? null);

                    if (! $this->hasValue($item['custo_unitario'] ?? null) && ! $this->hasValue($precoUnitarioFonte)) {
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
                    $revisaoAtributosVariacao = null;
                    $atributosAdicionadosVariacao = [];
                    $decisaoAtributosVariacao = null;
                    $variacaoExistente = false;

                    $variacao = null;

                    if (! $forcarProdutoNovo && ! empty($item['id_variacao'])) {
                        $variacao = ProdutoVariacao::with('atributos')->find($item['id_variacao']);
                    }

                    if (
                        ! $forcarProdutoNovo
                        && ! $variacao
                        && ! empty($item['codigo_barras'])
                    ) {
                        $variacao = ProdutoVariacao::with('atributos')
                            ->where('codigo_barras', trim((string) $item['codigo_barras']))
                            ->first();
                    }

                    if (! $forcarProdutoNovo && ! $variacao && ! empty($item['ref'])) {
                        // A referência pode NÃO ser única por variação.
                        // Regra: se for ambígua e o front não enviou `id_variacao`,
                        // não devemos "chutar" uma variação automaticamente.
                        $variacoesPorIdentificador = ProdutoVariacao::with(['produto.categoria', 'atributos'])
                            ->where(function ($query) use ($item) {
                                $this->aplicarBuscaPorIdentificador($query, (string) ($item['ref'] ?? ''));
                            })
                            ->get();

                        if ($variacoesPorIdentificador->count() === 1) {
                            $variacao = $variacoesPorIdentificador->first();
                        } elseif ($variacoesPorIdentificador->count() > 1) {
                            throw $this->erroReferenciaAmbiguaImportacao($item, $index, $variacoesPorIdentificador);
                        }
                    }

                    if ($variacao) {
                        $variacao = ProdutoVariacao::query()
                            ->with('atributos')
                            ->lockForUpdate()
                            ->findOrFail($variacao->id);
                        $variacaoExistente = true;
                        $revisaoAtributosVariacao = $this->analisarRevisaoAtributosVariacao($item, $variacao);

                        if ($revisaoAtributosVariacao['requerida']) {
                            $decisaoAtributosVariacao = trim((string) ($item['decisao_atributos_variacao'] ?? ''));

                            if (! in_array($decisaoAtributosVariacao, ['adicionar', 'manter'], true)) {
                                throw ValidationException::withMessages([
                                    "itens.$index.decisao_atributos_variacao" => [
                                        'Produto '.($index + 1).': escolha adicionar os atributos detectados ou manter o cadastro atual.',
                                    ],
                                ]);
                            }

                            if ($decisaoAtributosVariacao === 'adicionar') {
                                if ($revisaoAtributosVariacao['conflitos'] !== []) {
                                    throw ValidationException::withMessages([
                                        "itens.$index.decisao_atributos_variacao" => [
                                            'Produto '.($index + 1).': existem atributos conflitantes no cadastro. Mantenha o cadastro, selecione outra variação ou cadastre uma nova.',
                                        ],
                                    ]);
                                }

                                foreach ($revisaoAtributosVariacao['ausentes'] as $atributoAusente) {
                                    $atributoCriado = ProdutoVariacaoAtributo::firstOrCreate([
                                        'id_variacao' => $variacao->id,
                                        'atributo' => $atributoAusente['atributo'],
                                        'valor' => $atributoAusente['valor'],
                                    ]);

                                    if ($atributoCriado->wasRecentlyCreated) {
                                        $atributosAdicionadosVariacao[] = $atributoAusente;
                                    }
                                }

                                $variacao->load('atributos');
                            }
                        }
                    }

                    if (! $variacao) {
                        if (! $this->hasValue($item['ref'] ?? null)) {
                            throw ValidationException::withMessages([
                                "itens.$index.ref" => [
                                    'Produto '.($index + 1).': informe a referencia para cadastrar um produto novo.',
                                ],
                            ]);
                        }

                        $produto = Produto::firstOrCreate([
                            'nome' => $item['nome'],
                            'id_categoria' => $item['id_categoria'],
                        ], [
                            'id_fornecedor' => $fornecedorId,
                        ]);

                        $variacao = ProdutoVariacao::create([
                            'produto_id' => $produto->id,
                            'referencia' => $item['ref'] ?? null,
                            'sku_interno' => $item['sku_interno'] ?? null,
                            'nome' => $item['nome'],
                            'preco' => $valorUnit,
                            'custo' => $custoUnit,
                        ]);

                        foreach ($this->atributosListaProdutoImportacao($item) as $atributo) {
                            $nomeAtributo = trim((string) ($atributo['atributo'] ?? ''));
                            $valor = $atributo['valor'] ?? null;

                            if ($nomeAtributo === '' || is_array($valor) || is_object($valor)) {
                                continue;
                            }
                            if (is_numeric($valor)) {
                                $valor = (string) $valor;
                            }
                            if ($valor === null || trim((string) $valor) === '') {
                                continue;
                            }

                            ProdutoVariacaoAtributo::firstOrCreate(
                                [
                                    'id_variacao' => $variacao->id,
                                    'atributo' => StringHelper::normalizarAtributo($nomeAtributo),
                                    'valor' => trim((string) $valor),
                                ]
                            );
                        }
                    }

                    $this->registrarCodigosHistoricosPedido(
                        $variacao,
                        $item['ref'] ?? null,
                        $item['codigo_origem'] ?? null
                    );
                    $depositoRecebimentoId = $item['deposito_recebimento_id']
                        ?? $item['id_deposito']
                        ?? null;

                    $pedidoItem = PedidoItem::create([
                        'id_pedido' => $pedido->id,
                        'id_variacao' => $variacao->id,
                        'quantidade' => $quantidade,
                        'preco_unitario' => $valorUnit,
                        'custo_unitario' => $custoUnit,
                        'subtotal' => (float) $quantidade * (float) $valorUnit,
                        'id_deposito' => $depositoRecebimentoId,
                        'observacoes' => $item['atributos_nfe']['observacao'] ?? $item['atributos']['observacao'] ?? null,
                    ]);
                    $pedidoItensCriados->push([
                        'item' => $pedidoItem,
                        'antecipacoes' => array_values((array) ($item['antecipacoes'] ?? [])),
                        'movimentacao_estoque_tipo' => $tiposMovimentacaoPorIndice[$index] ?? self::MOVIMENTACAO_ENTRADA,
                    ]);

                    PedidoImportacaoItem::create([
                        'pedido_importacao_id' => $importacaoId ? (int) $importacaoId : null,
                        'pedido_id' => $pedido->id,
                        'pedido_item_id' => $pedidoItem->id,
                        'produto_id' => $variacao->produto_id,
                        'produto_variacao_id' => $variacao->id,
                        'acao' => $forcarProdutoNovo ? 'criado' : 'vinculado',
                        'dados_importados_json' => $this->removerMetadadosPreviewItem($item),
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
                            'id_deposito' => $depositoRecebimentoId,
                            'deposito_recebimento_id' => $depositoRecebimentoId,
                            'antecipacoes' => array_values((array) ($item['antecipacoes'] ?? [])),
                            'atributos' => $item['atributos'],
                            'atributos_lista' => $item['atributos_lista'],
                            'atributos_nfe' => $item['atributos_nfe'] ?? null,
                            'revisao_atributos_variacao' => $revisaoAtributosVariacao,
                            'decisao_atributos_variacao' => $decisaoAtributosVariacao,
                            'atributos_adicionados_variacao' => $atributosAdicionadosVariacao,
                        ],
                    ]);

                    if ($variacaoExistente && ($revisaoAtributosVariacao['requerida'] ?? false)) {
                        SierraLog::inventory('inventory.order_xml_import.variation_attributes_reviewed', [
                            'usuario_id' => $usuario->id,
                            'entity_type' => 'produto_variacao',
                            'entity_id' => $variacao->id,
                            'batch_id' => $importacaoId,
                            'pedido_id' => $pedido->id,
                            'pedido_item_id' => $pedidoItem->id,
                            'decisao' => $decisaoAtributosVariacao,
                            'atributos_ausentes' => $revisaoAtributosVariacao['ausentes'],
                            'atributos_conflitantes' => $revisaoAtributosVariacao['conflitos'],
                            'atributos_adicionados' => $atributosAdicionadosVariacao,
                        ]);
                    }
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
                    $usuario->id,
                    $fluxoV2,
                    $movimentarEstoque,
                    $entregue
                );

                $itensConfirmados = $pedido->itens()
                    ->with('variacao.produto', 'variacao.atributos')
                    ->get();

                return response()->json([
                    'message' => 'Pedido importado e salvo com sucesso.',
                    'id' => $pedido->id,
                    'tipo' => $pedido->tipo,
                    'origem_abastecimento' => $pedido->origem_abastecimento,
                    'itens' => $itensConfirmados->map(function ($item) {
                        return [
                            'id_variacao' => $item->variacao?->id,
                            'referencia' => $item->variacao?->referencia,
                            'sku_interno' => $item->variacao?->sku_interno,
                            'nome_produto' => $item->variacao?->produto?->nome,
                            'nome_completo' => $item->variacao?->nomeCompleto,
                            'categoria_id' => $item->variacao?->produto?->id_categoria,
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

    /** @param Collection<int,array{item:PedidoItem,antecipacoes:array,movimentacao_estoque_tipo:string}> $pedidoItens */
    private function aplicarMovimentacoesImportacao(
        Pedido $pedido,
        Collection $pedidoItens,
        ?int $usuarioId,
        bool $fluxoV2,
        bool $movimentarEstoque,
        bool $entregue
    ): void {
        $entregas = app(EntregaProdutoService::class);
        $entregaItens = $entregas->criarDemandaPedido($pedido, $usuarioId, false)
            ->keyBy('pedido_item_id');

        if (! $fluxoV2) {
            $this->aplicarMovimentacoesImportacaoLegada(
                $pedido,
                $pedidoItens,
                $entregaItens,
                $movimentarEstoque,
                $entregue,
                $usuarioId
            );

            return;
        }

        if (! $pedido->isVenda()) {
            return;
        }

        foreach ($pedidoItens as $itemIndice => $registro) {
            /** @var PedidoItem $pedidoItem */
            $pedidoItem = $registro['item'];
            $entrega = $entregaItens->get($pedidoItem->id);

            if (! $entrega) {
                continue;
            }

            foreach ((array) ($registro['antecipacoes'] ?? []) as $indice => $antecipacao) {
                $reservadoAntes = (int) $entrega->fresh()->quantidade_reservada;
                $quantidadeAntecipada = isset($antecipacao['quantidade']) ? (int) $antecipacao['quantidade'] : 0;
                $entregaAtualizada = $entregas->reservarItem(
                    $entrega,
                    isset($antecipacao['deposito_id']) ? (int) $antecipacao['deposito_id'] : null,
                    $quantidadeAntecipada,
                    $usuarioId,
                    'Atendimento antecipado com estoque atual; a chegada da fabrica permanecera como reposicao.',
                    "importacao-pedido:{$pedidoItem->id}:antecipacao:{$indice}"
                );

                $reservadoAgora = (int) $entregaAtualizada->quantidade_reservada;
                if ($reservadoAgora - $reservadoAntes < $quantidadeAntecipada) {
                    throw ValidationException::withMessages([
                        "itens.{$itemIndice}.antecipacoes.{$indice}.quantidade" => [
                            $entregaAtualizada->bloqueio_motivo ?: 'Estoque insuficiente para a antecipacao solicitada.',
                        ],
                    ]);
                }

                $entrega = $entregaAtualizada;
            }
        }
    }

    private function aplicarMovimentacoesImportacaoLegada(
        Pedido $pedido,
        Collection $pedidoItens,
        Collection $entregaItens,
        bool $movimentarEstoque,
        bool $entregue,
        ?int $usuarioId
    ): void {
        if (! $movimentarEstoque) {
            return;
        }

        $entregas = app(EntregaProdutoService::class);
        $movimentacoes = app(EstoqueMovimentacaoService::class);

        foreach ($pedidoItens as $registro) {
            /** @var PedidoItem $pedidoItem */
            $pedidoItem = $registro['item'];
            $tipoMovimentacao = $this->normalizarTipoMovimentacaoItem(
                (string) $pedido->tipo,
                $registro['movimentacao_estoque_tipo'] ?? null
            );
            $entrega = $entregaItens->get($pedidoItem->id);

            if (! $entrega) {
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
                    'Recebimento de reposicao importada (fluxo legado).',
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
                    'Saida de estoque registrada na importacao do pedido (fluxo legado).',
                    ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                    "importacao-pedido:{$pedidoItem->id}:saida"
                );

                if ($entregue) {
                    $entregas->entregarItem(
                        $entregaAtualizada,
                        $quantidade,
                        $usuarioId,
                        'Entrega ao cliente registrada na importacao do pedido (fluxo legado).',
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
                'observacao' => 'Entrada de fabrica registrada na importacao do pedido (fluxo legado).',
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
                'Reserva criada apos entrada de fabrica importada (fluxo legado).',
                "importacao-pedido:{$pedidoItem->id}:reserva"
            );
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

        return is_numeric($s) ? (float) $s : 0.0;
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
        return ! ($value === null || (is_string($value) && trim($value) === ''));
    }

    /**
     * Retorna os atributos efetivos como lista, preferindo o contrato novo e
     * usando o mapa legado apenas como fallback.
     *
     * @return array<int|string, array{atributo: string, valor: mixed}>
     */
    private function atributosListaProdutoImportacao(array $item): array
    {
        $atributos = $this->atributosListaDoContrato(
            $item['atributos_lista'] ?? null,
            $item['atributos'] ?? []
        );

        return array_filter(
            $atributos,
            fn (array $atributo) => ! in_array(
                strtolower(trim($atributo['atributo'])),
                self::ATRIBUTOS_FISCAIS_NFE,
                true
            )
        );
    }

    /** @return list<array{atributo: string, valor: string}> */
    private function atributosDetectadosProdutoImportacao(array $item): array
    {
        $fonte = is_array($item['atributos_detectados_lista'] ?? null)
            ? $item['atributos_detectados_lista']
            : $this->atributosListaProdutoImportacao($item);
        $atributos = [];
        $chaves = [];

        foreach ($fonte as $atributo) {
            if (! is_array($atributo)) {
                continue;
            }

            $nome = StringHelper::normalizarAtributo((string) ($atributo['atributo'] ?? $atributo['nome'] ?? ''));
            $valor = $atributo['valor'] ?? null;

            if (
                $nome === ''
                || in_array($nome, self::ATRIBUTOS_FISCAIS_NFE, true)
                || is_array($valor)
                || is_object($valor)
                || trim((string) $valor) === ''
            ) {
                continue;
            }

            $valor = trim((string) $valor);
            $chave = $nome.'|'.$this->normalizarValorAtributoComparacao($valor);
            if (isset($chaves[$chave])) {
                continue;
            }

            $chaves[$chave] = true;
            $atributos[] = ['atributo' => $nome, 'valor' => $valor];
        }

        return $atributos;
    }

    /**
     * @return array{
     *   requerida: bool,
     *   existentes: list<array{atributo: string, valor: string}>,
     *   detectados: list<array{atributo: string, valor: string}>,
     *   ausentes: list<array{atributo: string, valor: string}>,
     *   conflitos: list<array{atributo: string, valor_detectado: string, valores_existentes: list<string>}>
     * }
     */
    private function analisarRevisaoAtributosVariacao(array $item, ProdutoVariacao $variacao): array
    {
        $existentes = $this->atributosListaVariacao($variacao);
        $detectados = $this->atributosDetectadosProdutoImportacao($item);
        $existentesPorTipo = collect($existentes)->groupBy(
            fn (array $atributo) => StringHelper::normalizarAtributo($atributo['atributo'])
        );
        $ausentes = [];
        $conflitos = [];

        foreach ($detectados as $detectado) {
            $tipo = $detectado['atributo'];
            $valorNormalizado = $this->normalizarValorAtributoComparacao($detectado['valor']);
            $doMesmoTipo = $existentesPorTipo->get($tipo, collect());
            $parExiste = $doMesmoTipo->contains(
                fn (array $existente) => $this->normalizarValorAtributoComparacao($existente['valor']) === $valorNormalizado
            );

            if ($parExiste) {
                continue;
            }

            $ausentes[] = $detectado;
            if ($doMesmoTipo->isNotEmpty()) {
                $conflitos[] = [
                    'atributo' => $tipo,
                    'valor_detectado' => $detectado['valor'],
                    'valores_existentes' => $doMesmoTipo
                        ->pluck('valor')
                        ->unique()
                        ->values()
                        ->all(),
                ];
            }
        }

        return [
            'requerida' => $ausentes !== [],
            'existentes' => $existentes,
            'detectados' => $detectados,
            'ausentes' => $ausentes,
            'conflitos' => $conflitos,
        ];
    }

    /**
     * @return array<int|string, array{atributo: string, valor: mixed}>
     */
    private function atributosListaDoContrato(mixed $lista, mixed $mapa): array
    {
        if (is_array($lista)) {
            $normalizados = [];

            foreach ($lista as $indice => $atributo) {
                if (! is_array($atributo)) {
                    continue;
                }

                $normalizados[$indice] = [
                    'atributo' => trim((string) ($atributo['atributo'] ?? $atributo['nome'] ?? '')),
                    'valor' => $atributo['valor'] ?? null,
                ];
            }

            return $normalizados;
        }

        if (! is_array($mapa)) {
            return [];
        }

        $normalizados = [];
        foreach ($mapa as $atributo => $valor) {
            $normalizados[] = [
                'atributo' => trim((string) $atributo),
                'valor' => $valor,
            ];
        }

        return $normalizados;
    }

    /** @param array<int|string, array{atributo: string, valor: mixed}> $atributos */
    private function atributosListaParaMapa(array $atributos): array
    {
        $mapa = [];

        foreach ($atributos as $atributo) {
            $nome = trim((string) ($atributo['atributo'] ?? ''));
            $valor = $atributo['valor'] ?? null;

            if ($nome !== '' && ! is_array($valor) && ! is_object($valor)) {
                $mapa[$nome] = $valor;
            }
        }

        return $mapa;
    }

    private function normalizarContratoAtributosItem(array $item): array
    {
        $atributosLista = $this->atributosListaDoContrato(
            $item['atributos_lista'] ?? null,
            $item['atributos'] ?? []
        );
        $detectadosLista = $this->atributosListaDoContrato(
            $item['atributos_detectados_lista'] ?? null,
            $item['atributos_detectados'] ?? []
        );

        $item['atributos_lista'] = array_values($atributosLista);
        $item['atributos'] = $this->atributosListaParaMapa($atributosLista);
        $item['atributos_detectados_lista'] = array_values($detectadosLista);
        $item['atributos_detectados'] = $this->atributosListaParaMapa($detectadosLista);

        return $item;
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

    private function registrarCodigosHistoricosPedido(
        ProdutoVariacao $variacao,
        ?string $referencia,
        ?string $codigoOrigem
    ): void {
        $codigos = collect([$referencia, $codigoOrigem])
            ->map(fn ($codigo) => trim((string) $codigo))
            ->filter()
            ->unique()
            ->values();

        foreach ($codigos as $codigo) {
            $this->registrarCodigoHistoricoPedido($variacao, $codigo);
        }
    }

    private function registrarCodigoHistoricoPedido(ProdutoVariacao $variacao, string $codigo): void
    {
        $hashConteudo = sha1(json_encode([
            'codigo' => $codigo,
            'codigo_origem' => $codigo,
            'fonte' => 'importacao_pedido_xml',
        ], JSON_UNESCAPED_UNICODE));

        ProdutoVariacaoCodigoHistorico::updateOrCreate(
            [
                'produto_variacao_id' => $variacao->id,
                'hash_conteudo' => $hashConteudo,
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

    private function removerMetadadosPreviewItem(array $item): array
    {
        $item = Arr::except($item, self::METADADOS_PREVIEW_ITEM);

        foreach (array_keys($item) as $chave) {
            if (str_starts_with((string) $chave, '_ui')) {
                unset($item[$chave]);
            }
        }

        return $item;
    }

    /**
     * Mescla itens extraídos da importação XML com itens já cadastrados.
     *
     * - Enriquece com nome_completo
     * - Envia atributos da variação
     * - Envia dimensões do produto (largura, profundidade, altura) em "fixos"
     */
    public function mesclarItensComVariacoes(array $itens, ?string $estrategiaVinculo = null, array $opcoes = []): array
    {
        $categoriaSugerida = $this->normalizarCategoriaSugerida($opcoes['categoria_sugerida'] ?? null);

        return collect($itens)->values()->map(function ($item, int $index) use ($categoriaSugerida) {
            $item = $this->normalizarContratoAtributosItem((array) $item);
            $linha = $this->normalizarLinhaItem($item['linha'] ?? null, $index + 1);

            $ref = isset($item['codigo']) && trim((string) $item['codigo']) !== ''
                ? trim((string) $item['codigo'])
                : (isset($item['ref']) ? trim((string) $item['ref']) : null);
            $codigoBarras = isset($item['codigo_barras']) ? trim((string) $item['codigo_barras']) : null;

            if (! $ref && ! $codigoBarras) {
                $item['linha'] = $linha;

                return $this->aplicarCategoriaSugerida($item, $categoriaSugerida);
            }

            if ($this->possuiAtributosDetectados($item)) {
                return $this->mesclarItemComIdentificacaoAutomatica(
                    $item,
                    $linha,
                    $ref,
                    $categoriaSugerida
                );
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
                        'linha' => $linha,
                        'ref' => $ref,
                        'produto_id' => null,
                        'id_variacao' => null,
                        'variacao_nome' => null,
                        'nome_completo' => null,
                        'id_categoria' => $categoriaId,
                        'categoria' => $categoriaNome,
                        'atributos' => $item['atributos'] ?? [],
                        'fixos' => $item['fixos'] ?? [],
                        'variacoes_encontradas' => $variacoesEncontradas,
                    ]);

                    return $this->aplicarCategoriaSugerida($itemMapeado, $categoriaSugerida);
                }
            }

            // Produto não encontrado: preservar dados da importação.
            $categoriaId = $item['id_categoria'] ?? null;

            $itemMapeado = array_merge($item, [
                'linha' => $linha,
                'ref' => $ref,
                'produto_id' => null,
                'id_variacao' => null,
                'variacao_nome' => null,
                'id_categoria' => $categoriaId,
                'categoria' => $item['categoria'] ?? $this->categoriaNome($categoriaId),
                'atributos' => $item['atributos'] ?? [],
                'fixos' => $item['fixos'] ?? [],
            ]);

            return $this->aplicarCategoriaSugerida($itemMapeado, $categoriaSugerida);
        })->toArray();
    }

    private function possuiAtributosDetectados(array $item): bool
    {
        return collect($this->atributosListaDoContrato(
            $item['atributos_detectados_lista'] ?? null,
            $item['atributos_detectados'] ?? []
        ))->contains(
            fn (array $atributo) => ! is_array($atributo['valor'] ?? null)
                && ! is_object($atributo['valor'] ?? null)
                && trim((string) ($atributo['valor'] ?? '')) !== ''
        );
    }

    private function mesclarItemComIdentificacaoAutomatica(
        array $item,
        int $linha,
        ?string $referencia,
        ?array $categoriaSugerida
    ): array {
        $item['nome_detectado'] = $item['nome_detectado'] ?? ($item['nome'] ?? null);
        $codigoOrigem = trim((string) ($item['codigo_origem'] ?? ''));
        $atributosIdentidade = $this->atributosIdentidadeImportados(
            $this->atributosListaDoContrato(
                $item['atributos_detectados_lista'] ?? null,
                $item['atributos_detectados'] ?? []
            )
        );
        $candidatos = $this->buscarCandidatosIdentificacaoAutomatica($referencia, $codigoOrigem);

        $avaliados = $candidatos
            ->map(function (ProdutoVariacao $variacao) use ($atributosIdentidade, $codigoOrigem) {
                return [
                    'variacao' => $variacao,
                    'compatibilidade' => $this->avaliarCompatibilidadeVariacao(
                        $variacao,
                        $atributosIdentidade,
                        $codigoOrigem
                    ),
                ];
            })
            ->sort(fn (array $a, array $b) => $this->compararCandidatosVariacao($a, $b))
            ->values();

        $elegiveis = $avaliados
            ->filter(fn (array $avaliado) => $avaliado['compatibilidade']['elegivel'])
            ->values();
        $historicosExatos = $elegiveis
            ->filter(fn (array $avaliado) => $avaliado['compatibilidade']['codigo_origem_exato'])
            ->values();
        $poolDecisao = $historicosExatos->isNotEmpty() ? $historicosExatos : $elegiveis;
        $melhores = collect();

        if ($poolDecisao->isNotEmpty()) {
            $maiorQuantidadeCompativeis = $poolDecisao->max(
                fn (array $avaliado) => count($avaliado['compatibilidade']['compativeis'])
            );
            $melhores = $poolDecisao
                ->filter(fn (array $avaliado) => count($avaliado['compatibilidade']['compativeis']) === $maiorQuantidadeCompativeis)
                ->values();
        }

        $compatibilidades = $avaliados
            ->mapWithKeys(fn (array $avaliado) => [
                $avaliado['variacao']->id => $avaliado['compatibilidade'],
            ])
            ->all();
        $variacoesEncontradas = $this->variacoesParaListaPreview(
            $avaliados->pluck('variacao'),
            $compatibilidades
        );
        $totalAtributos = count($atributosIdentidade);

        if ($melhores->count() === 1) {
            $melhor = $melhores->first();
            /** @var ProdutoVariacao $variacao */
            $variacao = $melhor['variacao'];
            $compatibilidade = $melhor['compatibilidade'];
            $itemMapeado = $this->mapearItemComVariacaoEncontrada($item, $linha, $referencia, $variacao);

            return array_merge($itemMapeado, [
                'forcar_produto_novo' => false,
                'variacoes_encontradas' => $variacoesEncontradas,
                'vinculo_sugerido' => [
                    'decisao' => 'existente',
                    'id_variacao' => $variacao->id,
                    'motivo' => $compatibilidade['codigo_origem_exato']
                        ? 'codigo_origem_exato'
                        : 'atributos_compativeis',
                    'compativeis' => count($compatibilidade['compativeis']),
                    'total' => $totalAtributos,
                ],
            ]);
        }

        $possuiConflitoConjuntoRepetido = $avaliados->contains(
            fn (array $avaliado) => ($avaliado['compatibilidade']['conflitos_conjunto_repetido'] ?? []) !== []
        );
        $decisao = $melhores->isEmpty()
            ? ($possuiConflitoConjuntoRepetido ? 'revisao' : 'nova')
            : 'revisao';
        $itemMapeado = $this->mapearItemSemVariacao($item, $linha, $referencia);
        $itemMapeado = array_merge($itemMapeado, [
            'forcar_produto_novo' => $decisao === 'nova',
            'variacoes_encontradas' => $variacoesEncontradas,
            'vinculo_sugerido' => [
                'decisao' => $decisao,
                'id_variacao' => null,
                'motivo' => $decisao === 'nova'
                    ? 'nenhuma_variacao_compativel'
                    : ($possuiConflitoConjuntoRepetido
                        ? 'conflito_conjunto_repetido'
                        : 'empate_compatibilidade'),
                'compativeis' => $melhores->isNotEmpty()
                    ? count($melhores->first()['compatibilidade']['compativeis'])
                    : 0,
                'total' => $totalAtributos,
            ],
        ]);

        return $this->aplicarCategoriaSugerida($itemMapeado, $categoriaSugerida);
    }

    private function buscarCandidatosIdentificacaoAutomatica(?string $referencia, string $codigoOrigem): Collection
    {
        $referencia = trim((string) $referencia);

        if ($referencia === '' && $codigoOrigem === '') {
            return collect();
        }

        return ProdutoVariacao::with(['produto.categoria', 'atributos', 'codigosHistoricos'])
            ->where(function ($query) use ($referencia, $codigoOrigem) {
                if ($referencia !== '') {
                    $query->where('referencia', $referencia);
                }

                if ($codigoOrigem !== '') {
                    $metodo = $referencia !== '' ? 'orWhereHas' : 'whereHas';
                    $query->{$metodo}('codigosHistoricos', function ($codigoQuery) use ($codigoOrigem) {
                        $codigoQuery->where('codigo_origem', $codigoOrigem);
                    });
                }
            })
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @param  array<int|string, array{atributo: string, valor: mixed}>  $atributos
     * @return array<string, list<string>>
     */
    private function atributosIdentidadeImportados(array $atributos): array
    {
        $normalizados = [];

        foreach ($atributos as $atributo) {
            $valor = $atributo['valor'] ?? null;
            if (is_array($valor) || is_object($valor)) {
                continue;
            }

            $atributoNormalizado = StringHelper::normalizarAtributo(
                (string) ($atributo['atributo'] ?? '')
            );
            $valor = trim((string) $valor);

            if (! in_array($atributoNormalizado, self::ATRIBUTOS_IDENTIDADE_VARIACAO, true) || $valor === '') {
                continue;
            }

            $chaveValor = $this->normalizarValorAtributoComparacao($valor);
            $valoresExistentes = $normalizados[$atributoNormalizado] ?? [];
            $jaExiste = collect($valoresExistentes)->contains(
                fn (string $existente) => $this->normalizarValorAtributoComparacao($existente) === $chaveValor
            );

            if (! $jaExiste) {
                $normalizados[$atributoNormalizado][] = $valor;
            }
        }

        return $normalizados;
    }

    private function avaliarCompatibilidadeVariacao(
        ProdutoVariacao $variacao,
        array $atributosImportados,
        string $codigoOrigem
    ): array {
        $atributosCandidato = $this->atributosIdentidadeVariacao($variacao);
        $compativeis = [];
        $conflitos = [];
        $conflitosConjuntoRepetido = [];
        $ausentes = [];

        foreach ($atributosImportados as $atributo => $valoresImportados) {
            $valoresCandidato = $atributosCandidato[$atributo] ?? [];
            $conjuntoImportado = $this->normalizarConjuntoValoresAtributo($valoresImportados);
            $conjuntoCandidato = $this->normalizarConjuntoValoresAtributo($valoresCandidato);
            $exigeConjuntoCompleto = count($conjuntoImportado) > 1 || count($conjuntoCandidato) > 1;

            if ($valoresCandidato === []) {
                if ($exigeConjuntoCompleto) {
                    $conflitos[] = $atributo;
                    $conflitosConjuntoRepetido[] = $atributo;
                } else {
                    $ausentes[] = $atributo;
                }

                continue;
            }

            $encontrado = $exigeConjuntoCompleto
                ? $conjuntoImportado === $conjuntoCandidato
                : ($conjuntoImportado[0] ?? null) === ($conjuntoCandidato[0] ?? null);

            if ($encontrado) {
                $compativeis[] = $atributo;
            } else {
                $conflitos[] = $atributo;
                if ($exigeConjuntoCompleto) {
                    $conflitosConjuntoRepetido[] = $atributo;
                }
            }
        }

        $total = count($atributosImportados);
        $codigoOrigemExato = $codigoOrigem !== ''
            && $variacao->relationLoaded('codigosHistoricos')
            && $variacao->codigosHistoricos->contains(
                fn (ProdutoVariacaoCodigoHistorico $codigo) => trim((string) $codigo->codigo_origem) === $codigoOrigem
            );

        return [
            'compativeis' => $compativeis,
            'conflitos' => $conflitos,
            'conflitos_conjunto_repetido' => array_values(array_unique($conflitosConjuntoRepetido)),
            'ausentes' => $ausentes,
            'total' => $total,
            'percentual' => $total > 0 ? (int) round((count($compativeis) / $total) * 100) : 0,
            'codigo_origem_exato' => $codigoOrigemExato,
            'elegivel' => $compativeis !== [] && $conflitos === [],
        ];
    }

    /** @param list<string> $valores */
    private function normalizarConjuntoValoresAtributo(array $valores): array
    {
        $normalizados = collect($valores)
            ->map(fn ($valor) => $this->normalizarValorAtributoComparacao((string) $valor))
            ->filter(fn (string $valor) => $valor !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $normalizados;
    }

    private function atributosIdentidadeVariacao(ProdutoVariacao $variacao): array
    {
        $diretos = [];
        $legados = [];

        if (! $variacao->relationLoaded('atributos')) {
            return [];
        }

        foreach ($variacao->atributos as $atributo) {
            $nome = StringHelper::normalizarAtributo((string) $atributo->atributo);
            $valor = trim((string) $atributo->valor);

            if ($valor === '') {
                continue;
            }

            if (in_array($nome, self::ATRIBUTOS_IDENTIDADE_VARIACAO, true)) {
                $diretos[$nome][] = $valor;

                continue;
            }

            if (in_array($nome, ['modelo_referencia', 'acabamentos'], true)) {
                foreach ($this->extrairAtributosLegadosComparacao($valor) as $atributoLegado => $valorLegado) {
                    $legados[$atributoLegado][] = $valorLegado;
                }
            }
        }

        return $diretos + $legados;
    }

    private function extrairAtributosLegadosComparacao(string $valor): array
    {
        $extraidos = [];
        $texto = trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);

        if (preg_match('/(?:^|\s)COR\s*:?\s*(.+?)(?=\s+(?:TEC(?:IDO)?|COURO|ASS)\s*:?\s*|$)/iu', $texto, $cor)) {
            $valorCor = trim($cor[1]);
            $atributoCor = preg_match('/\b(?:INOX|ALUMINIO|ALUMÍNIO|FERRO|METAL|GOLD|ONIX|ÔNIX)\b/iu', $valorCor)
                ? 'metal_vidro'
                : 'madeira';
            $extraidos[$atributoCor] = $valorCor;
        }

        if (preg_match('/(?:^|\s)(?:TEC(?:IDO)?|COURO|ASS)\s*:?\s*(.+)$/iu', $texto, $tecido)) {
            $extraidos['tecido_1'] = trim($tecido[1]);
        }

        if ($extraidos === [] && str_contains($texto, '#')) {
            [$madeira, $acabamento] = array_pad(array_map('trim', explode('#', $texto, 2)), 2, '');

            if ($madeira !== '' && $acabamento !== '') {
                $extraidos['madeira'] = $madeira;
                $extraidos['metal_vidro'] = $acabamento;
            }
        }

        return array_filter($extraidos, fn (string $item) => $item !== '');
    }

    private function normalizarValorAtributoComparacao(string $valor): string
    {
        $valor = Str::ascii(mb_strtolower(trim($valor), 'UTF-8'));

        return preg_replace('/[^a-z0-9]+/', '', $valor) ?? '';
    }

    private function compararCandidatosVariacao(array $a, array $b): int
    {
        $compatibilidadeA = $a['compatibilidade'];
        $compatibilidadeB = $b['compatibilidade'];
        $chavesA = [
            $compatibilidadeA['elegivel'] ? 1 : 0,
            $compatibilidadeA['elegivel'] && $compatibilidadeA['codigo_origem_exato'] ? 1 : 0,
            count($compatibilidadeA['compativeis']),
            -count($compatibilidadeA['conflitos']),
            -count($compatibilidadeA['ausentes']),
        ];
        $chavesB = [
            $compatibilidadeB['elegivel'] ? 1 : 0,
            $compatibilidadeB['elegivel'] && $compatibilidadeB['codigo_origem_exato'] ? 1 : 0,
            count($compatibilidadeB['compativeis']),
            -count($compatibilidadeB['conflitos']),
            -count($compatibilidadeB['ausentes']),
        ];

        for ($indice = 0; $indice < count($chavesA); $indice++) {
            if ($chavesA[$indice] !== $chavesB[$indice]) {
                return $chavesB[$indice] <=> $chavesA[$indice];
            }
        }

        return $a['variacao']->id <=> $b['variacao']->id;
    }

    private function mapearItemSemVariacao(array $item, int $linha, ?string $referencia): array
    {
        $categoriaId = $item['id_categoria'] ?? null;

        return array_merge($item, [
            'linha' => $linha,
            'ref' => $referencia,
            'produto_id' => null,
            'id_variacao' => null,
            'variacao_nome' => null,
            'nome_completo' => null,
            'id_categoria' => $categoriaId,
            'categoria' => $item['categoria'] ?? $this->categoriaNome($categoriaId),
            'atributos' => $item['atributos'] ?? [],
            'fixos' => $item['fixos'] ?? [],
        ]);
    }

    private function normalizarCategoriaSugerida(mixed $categoria): ?array
    {
        if (! is_array($categoria)) {
            return null;
        }

        $id = $categoria['id'] ?? $categoria['id_categoria'] ?? null;
        $nome = trim((string) ($categoria['nome'] ?? $categoria['categoria'] ?? ''));

        if (! is_numeric($id) || (int) $id <= 0 || $nome === '') {
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
        $revisaoAtributosVariacao = $this->analisarRevisaoAtributosVariacao($item, $variacao);

        $atributosVariacaoLista = $this->atributosListaVariacao($variacao);
        $atributosImportacaoLista = $this->atributosListaDoContrato(
            $item['atributos_lista'] ?? null,
            $item['atributos'] ?? []
        );
        $nomesVariacao = collect($atributosVariacaoLista)
            ->map(fn (array $atributo) => StringHelper::normalizarAtributo($atributo['atributo']))
            ->flip();

        // O cadastro prevalece por nome, mas todos os valores repetidos da variacao sao preservados.
        $atributosFinalLista = collect($atributosImportacaoLista)
            ->reject(fn (array $atributo) => $nomesVariacao->has(
                StringHelper::normalizarAtributo($atributo['atributo'])
            ))
            ->concat($atributosVariacaoLista)
            ->values()
            ->all();
        $atributosFinal = $this->atributosListaParaMapa($atributosFinalLista);

        // Dimensões vindas do produto
        $fixosDb = [
            'largura' => $produto?->largura,
            'profundidade' => $produto?->profundidade,
            'altura' => $produto?->altura,
        ];

        $fixosImportacao = $item['fixos'] ?? [];
        $fixosFinal = array_merge(
            $fixosImportacao,
            array_filter($fixosDb, fn ($v) => ! is_null($v))
        );

        $categoriaId = $produto?->id_categoria;
        $categoriaNome = $produto?->categoria?->nome ?? $this->categoriaNome($categoriaId);

        return array_merge($item, [
            'linha' => $linha,
            'ref' => $ref,
            'sku_interno' => $variacao->sku_interno,
            'nome' => $produto?->nome ?? $variacao->nome,
            'produto_id' => $variacao->produto_id,
            'id_variacao' => $variacao->id,
            'variacao_nome' => $variacao->nome,
            'nome_completo' => $variacao->nome_completo,
            'id_categoria' => $categoriaId,
            'categoria' => $categoriaNome,
            'atributos' => $atributosFinal,
            'atributos_lista' => $atributosFinalLista,
            'revisao_atributos_variacao' => $revisaoAtributosVariacao,
            'decisao_atributos_variacao' => null,
            'fixos' => $fixosFinal,
            // garante que o front não exiba seleção antiga
            'variacoes_encontradas' => [],
        ]);
    }

    /** @return list<array{atributo: string, valor: string}> */
    private function atributosListaVariacao(ProdutoVariacao $variacao): array
    {
        if (! $variacao->relationLoaded('atributos')) {
            return [];
        }

        return $variacao->atributos
            ->map(fn ($atributo) => [
                'atributo' => (string) $atributo->atributo,
                'valor' => (string) $atributo->valor,
            ])
            ->sortBy(fn (array $atributo) => sprintf(
                '%s|%s',
                StringHelper::normalizarAtributo($atributo['atributo']),
                $this->normalizarValorAtributoComparacao($atributo['valor'])
            ))
            ->values()
            ->all();
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

    /**
     * @param  Collection<int, ProdutoVariacao>  $variacoes
     */
    private function erroReferenciaAmbiguaImportacao(array $item, int $index, Collection $variacoes): ValidationException
    {
        $field = "itens.{$index}.selecao_variacao";
        $message = $this->mensagemReferenciaAmbiguaImportacao($item, $index);
        $exception = ValidationException::withMessages([
            $field => [$message],
        ]);

        $exception->response = response()->json([
            'message' => $message,
            'errors' => [
                $field => [$message],
            ],
            'itens' => [
                $index => [
                    'variacoes_encontradas' => $this->variacoesParaListaPreview($variacoes),
                ],
            ],
        ], 422);

        return $exception;
    }

    private function rotuloItemImportacao(array $item, int $index): string
    {
        $prefixo = 'Produto '.($index + 1);

        foreach (['nome_completo', 'nome', 'descricao'] as $campo) {
            $valor = $this->normalizarTextoMensagemImportacao($item[$campo] ?? null);

            if ($valor !== '') {
                return "{$prefixo}: {$valor}";
            }
        }

        return $prefixo;
    }

    /**
     * @param  list<string>  $itensSemSaida
     */
    private function mensagemVendaEntregueItensSemSaida(array $itensSemSaida): string
    {
        $total = count($itensSemSaida);

        if ($total === 1) {
            return 'Pedido entregue: este item precisa estar como Saída para baixar o estoque. Altere para Saída ou use "Salvar sem movimentar". Item pendente: '.$itensSemSaida[0].'.';
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
        if (! is_numeric($categoriaId)) {
            return null;
        }

        return Categoria::query()->whereKey($categoriaId)->value('nome');
    }

    /**
     * @param  Collection<int, ProdutoVariacao>  $variacoesPorReferencia
     * @return list<array<string, mixed>>
     */
    private function variacoesParaListaPreview(
        Collection $variacoesPorReferencia,
        array $compatibilidades = []
    ): array {
        return $variacoesPorReferencia->map(function (ProdutoVariacao $v) use ($compatibilidades) {
            $produto = $v->produto;
            $categoriaId = $produto?->id_categoria;

            $atributosLista = $this->atributosListaVariacao($v);
            $atributos = $this->atributosListaParaMapa($atributosLista);

            $fixosDb = [
                'largura' => $produto?->largura,
                'profundidade' => $produto?->profundidade,
                'altura' => $produto?->altura,
            ];

            $preview = [
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
                'atributos_lista' => $atributosLista,
                'fixos' => array_filter($fixosDb, fn ($val) => ! is_null($val)),
            ];

            if (isset($compatibilidades[$v->id])) {
                $preview['compatibilidade'] = $compatibilidades[$v->id];
            }

            return $preview;
        })->values()->toArray();
    }

    private function itemDeveForcarProdutoNovo(Request $request, array $item): bool
    {
        return filter_var($item['forcar_produto_novo'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
