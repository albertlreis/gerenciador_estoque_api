<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\PedidoImportacao;
use App\Enums\PedidoStatus;
use App\Helpers\StringHelper;
use App\Support\Dates\DateNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Serviço responsável pela importação de pedidos via PDF.
 */
class ImportacaoPedidoService
{
    /**
     * Confirma os dados da importação de um pedido, salvando no banco.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function confirmarImportacaoPDF(Request $request): JsonResponse
    {
        Log::info('Importação PDF - confirmação iniciada', [
            'usuario_id' => Auth::id(),
            'importacao_id' => $request->input('importacao_id'),
            'itens_total' => is_array($request->input('itens')) ? count($request->input('itens')) : 0,
        ]);

        $validator = Validator::make($request->all(), [
            'pedido.tipo'          => 'required|in:venda,reposicao',
            'importacao_id'        => 'nullable|integer|exists:pedido_importacoes,id',
            'tipo_importacao'      => 'nullable|in:PRODUTOS_PDF_SIERRA,PRODUTOS_PDF_AVANTI,PRODUTOS_PDF_QUAKER,ADORNOS_XML_NFE',

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
        ]);

        // Condicional: se for venda, cliente.id é obrigatório
        $validator->sometimes('cliente.id', 'required|numeric|min:1', function ($input) {
            return data_get($input, 'pedido.tipo') === Pedido::TIPO_VENDA;
        });

        if ($validator->fails()) {
            Log::warning('Importação PDF - validação falhou', [
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
                $item['nome'] = trim((string) ($item['nome'] ?? ''));
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
                    Log::info('Importação PDF - item normalizado para persistência', [
                        'index' => $index,
                        'referencia' => $item['ref'] ?? null,
                        'quantidade' => $quantidade,
                        'preco_unitario' => $valorUnit,
                        'custo_unitario' => $custoUnit,
                        'valor_total_linha' => $this->toDecimal($item['valor_total_linha'] ?? ($item['valor_total'] ?? 0)),
                    ]);
                }

                $variacao = null;

                if (!empty($item['id_variacao'])) {
                    $variacao = ProdutoVariacao::with('atributos')->find($item['id_variacao']);
                }

                if (!$variacao && !empty($item['ref'])) {
                    $variacao = ProdutoVariacao::with('atributos')
                        ->where('referencia', $item['ref'])
                        ->first();
                }

                if (!$variacao) {
                    $produto = Produto::firstOrCreate([
                        'nome'         => $item['nome'],
                        'id_categoria' => $item['id_categoria'],
                    ]);

                    $variacao = ProdutoVariacao::create([
                        'produto_id' => $produto->id,
                        'referencia' => $item['ref'] ?? null,
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

            Log::info('Importação PDF - pedido confirmado', [
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
                        'nome_produto'  => $item->variacao?->produto?->nome,
                        'nome_completo' => $item->variacao?->nomeCompleto,
                        'categoria_id'  => $item->variacao?->produto?->id_categoria,
                    ];
                }),
            ]);
            });
        } catch (ValidationException $e) {
            Log::warning('Importação PDF - erro de normalização/validação', [
                'usuario_id' => Auth::id(),
                'erros' => $e->errors(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Importação PDF - erro ao confirmar', [
                'usuario_id' => Auth::id(),
                'mensagem' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            Log::info('Importação PDF - confirmação finalizada', [
                'usuario_id' => Auth::id(),
                'importacao_id' => $request->input('importacao_id'),
            ]);
        }
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
    public function mesclarItensComVariacoes(array $itens): array
    {
        return collect($itens)->map(function ($item) {

            $ref = $item['codigo'] ?? $item['ref'] ?? null;
            if (!$ref) {
                return $item;
            }

            /** @var ProdutoVariacao|null $variacao */
            $variacao = ProdutoVariacao::with(['produto.categoria', 'atributos'])
                ->where('referencia', $ref)
                ->first();

            if ($variacao) {

                $produto = $variacao->produto;

                // Mapeia atributos da variação para array simples chave => valor
                $atributosVariacao = $variacao->atributos
                    ->mapWithKeys(fn($attr) => [$attr->atributo => $attr->valor])
                    ->toArray();

                // Atributos vindos do PDF (se houver) – db sobrescreve o que vier errado
                $atributosPdf = $item['atributos'] ?? [];

                $atributosFinal = array_merge($atributosPdf, $atributosVariacao);

                // Dimensões vindas do produto
                $fixosDb = [
                    'largura'      => $produto?->largura,
                    'profundidade' => $produto?->profundidade,
                    'altura'       => $produto?->altura,
                ];

                $fixosPdf = $item['fixos'] ?? [];

                $fixosFinal = array_merge(
                    $fixosPdf,
                    array_filter($fixosDb, fn($v) => !is_null($v))
                );

                return array_merge($item, [
                    "ref"           => $ref,
                    "nome"          => $produto?->nome ?? $variacao->nome,
                    "produto_id"    => $variacao->produto_id,
                    "id_variacao"   => $variacao->id,
                    "variacao_nome" => $variacao->nome,
                    "nome_completo" => $variacao->nome_completo,
                    "id_categoria"  => $produto?->id_categoria,
                    "categoria"     => $produto?->categoria?->nome,
                    "atributos"     => $atributosFinal,
                    "fixos"         => $fixosFinal,
                ]);
            }

            // Produto não encontrado → usar dados do PDF
            return array_merge($item, [
                "ref"           => $ref,
                "produto_id"    => null,
                "id_variacao"   => null,
                "variacao_nome" => null,
                "id_categoria"  => $item['id_categoria'] ?? null,
                "categoria"  => $item['categoria'] ?? null,
                "atributos"     => $item['atributos'] ?? [],
                "fixos"         => $item['fixos'] ?? [],
            ]);
        })->toArray();
    }
}

