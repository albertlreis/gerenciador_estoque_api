<?php

namespace App\Services;

use App\DTOs\FiltroMovimentacaoEstoqueDTO;
use App\Enums\EstoqueMovimentacaoTipo;
use App\Http\Resources\MovimentacaoResource;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\EstoqueTransferencia;
use App\Models\EstoqueTransferenciaItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Serviço responsável pelas consultas e regras de movimentações de estoque.
 */
class EstoqueMovimentacaoService
{
    /**
     * Busca movimentações de estoque com base nos filtros fornecidos.
     *
     * @param  FiltroMovimentacaoEstoqueDTO  $filtros
     * @return LengthAwarePaginator
     */
    public function buscarComFiltros(FiltroMovimentacaoEstoqueDTO $filtros): LengthAwarePaginator
    {
        $query = EstoqueMovimentacao::query()
            ->with([
                'variacao.produto',
                'variacao.atributos',
                'usuario',
                'depositoOrigem',
                'depositoDestino',
            ]);

        // --- Filtros ---
        if (!empty($filtros->variacao)) {
            $query->where('id_variacao', (int) $filtros->variacao);
        }

        if (!empty($filtros->tipo)) {
            $query->where('tipo', $filtros->tipo);
        }

        if (!empty($filtros->produto)) {
            $produto = trim((string) $filtros->produto);

            // Se vier número, aceita como possível ID de produto/variação
            if (ctype_digit($produto)) {
                $idPossivel = (int) $produto;
                $query->where(function (Builder $q) use ($idPossivel, $produto) {
                    $q->whereHas('variacao.produto', fn (Builder $sub) => $sub->where('produtos.id', $idPossivel))
                        ->orWhere('id_variacao', $idPossivel)
                        ->orWhereHas('variacao', fn (Builder $sub) => $sub->where('referencia', 'like', "%{$produto}%"));
                });
            } else {
                $query->where(function (Builder $q) use ($produto) {
                    $q->whereHas('variacao.produto', fn (Builder $sub) => $sub->where('nome', 'like', "%{$produto}%"))
                        ->orWhereHas('variacao', fn (Builder $sub) => $sub->where('referencia', 'like', "%{$produto}%"));
                });
            }
        }

        if (!empty($filtros->categoria)) {
            $categoriaId = (int) $filtros->categoria;
            $query->whereHas('variacao.produto', fn (Builder $sub) => $sub->where('id_categoria', $categoriaId));
        }

        if (!empty($filtros->fornecedor)) {
            $fornecedorId = (int) $filtros->fornecedor;
            $query->whereHas('variacao.produto', fn (Builder $sub) => $sub->where('id_fornecedor', $fornecedorId));
        }

        if (!empty($filtros->deposito)) {
            $depositoId = (int) $filtros->deposito;
            $query->where(function (Builder $q) use ($depositoId) {
                $q->where('id_deposito_origem', $depositoId)
                    ->orWhere('id_deposito_destino', $depositoId);
            });
        }

        if (!empty($filtros->periodo) && is_array($filtros->periodo) && count($filtros->periodo) === 2) {
            [$ini, $fim] = $filtros->periodo;
            $ini = $ini ? Carbon::parse($ini)->startOfDay() : null;
            $fim = $fim ? Carbon::parse($fim)->endOfDay()   : null;
            if ($ini && $fim) {
                $query->whereBetween('data_movimentacao', [$ini, $fim]);
            }
        }

        // --- Ordenação ---
        $allowedSortFields = [
            'produto_nome',
            'tipo',
            'quantidade',
            'data_movimentacao',
            'id', // fallback
        ];

        $sortField = in_array($filtros->sortField, $allowedSortFields, true)
            ? $filtros->sortField
            : 'data_movimentacao';

        $sortOrder = strtolower((string) $filtros->sortOrder) === 'asc' ? 'asc' : 'desc';

        // produto_nome via subselect (mantido seu padrão)
        if ($sortField === 'produto_nome') {
            $sub = DB::raw(
                '(select nome from produtos where produtos.id = ' .
                '(select produto_id from produto_variacoes where produto_variacoes.id = estoque_movimentacoes.id_variacao))'
            );
            $query->orderBy($sub, $sortOrder)->orderBy('id', 'desc');
        } else {
            $query->orderBy($sortField, $sortOrder)->orderBy('id', 'desc');
        }

        // --- Paginação ---
        $perPage = (int) ($filtros->perPage ?? 15);
        $perPage = $perPage > 0 ? $perPage : 15;

        return $query->paginate($perPage);
    }

    /**
     * Registra movimentação manual (entrada/saída/transferência/estorno/consignação/assistência).
     *
     * @param array{
     *   id_variacao:int,
     *   id_deposito_origem:int|null,
     *   id_deposito_destino:int|null,
     *   tipo:string,
     *   quantidade:int,
     *   observacao:string|null,
     *   data_movimentacao:string|\DateTimeInterface|null,
     *   lote_id:string|null,
     *   ref_type:string|null,
     *   ref_id:int|null,
     *   pedido_id:int|null,
     *   pedido_item_id:int|null,
     *   reserva_id:int|null
     * } $dados
     */
    public function registrarMovimentacaoManual(array $dados, ?int $usuarioId = null): EstoqueMovimentacao
    {
        $dados['id_usuario'] = $usuarioId;
        return $this->movimentar($dados);
    }

    /**
     * Registra a movimentação de envio para assistência (estoque → depósito da assistência).
     */
    public function registrarEnvioAssistencia(
        int $variacaoId,
        int $depositoOrigemId,
        int $depositoAssistenciaId,
        int $quantidade,
        ?int $usuarioId,
        ?string $observacao = null
    ): EstoqueMovimentacao {
        return $this->movimentar([
            'id_variacao'         => $variacaoId,
            'id_deposito_origem'  => $depositoOrigemId,
            'id_deposito_destino' => $depositoAssistenciaId,
            'tipo'                => EstoqueMovimentacaoTipo::ASSISTENCIA_ENVIO->value, // 'assistencia_envio'
            'quantidade'          => $quantidade,
            'id_usuario'          => $usuarioId,
            'observacao'          => $observacao,
        ]);
    }

    /**
     * Registra a movimentação de retorno da assistência (dep. assistência → depósito retorno).
     */
    public function registrarRetornoAssistencia(
        int $variacaoId,
        int $depositoAssistenciaId,
        int $depositoRetornoId,
        int $quantidade,
        ?int $usuarioId,
        ?string $observacao = null
    ): EstoqueMovimentacao {
        return $this->movimentar([
            'id_variacao'         => $variacaoId,
            'id_deposito_origem'  => $depositoAssistenciaId,
            'id_deposito_destino' => $depositoRetornoId,
            'tipo'                => EstoqueMovimentacaoTipo::ASSISTENCIA_RETORNO->value, // 'assistencia_retorno'
            'quantidade'          => $quantidade,
            'id_usuario'          => $usuarioId,
            'observacao'          => $observacao,
        ]);
    }

    /**
     * Movimenta estoque: valida, ajusta saldos e persiste a movimentação.
     *
     * @param  array{id_variacao:int,id_deposito_origem:int|null,id_deposito_destino:int|null,tipo:string,quantidade:int,id_usuario:int|null,observacao:string|null}  $dados
     */
    private function movimentar(array $dados): EstoqueMovimentacao
    {
        // validações mínimas
        $variacaoId = (int) Arr::get($dados, 'id_variacao');
        $origem     = Arr::get($dados, 'id_deposito_origem');
        $destino    = Arr::get($dados, 'id_deposito_destino');
        $tipo       = (string) Arr::get($dados, 'tipo');
        $qtd        = (int) Arr::get($dados, 'quantidade');

        if ($variacaoId <= 0) {
            throw new InvalidArgumentException('Variação inválida.');
        }
        if ($qtd <= 0) {
            throw new InvalidArgumentException('Quantidade deve ser positiva.');
        }
        if ($origem && $destino && (int)$origem === (int)$destino) {
            throw new InvalidArgumentException('Depósito de origem e destino não podem ser o mesmo.');
        }
        if (!$origem && !$destino) {
            throw new InvalidArgumentException('Movimentação inválida: origem e destino ausentes.');
        }

        return DB::transaction(function () use ($dados, $variacaoId, $origem, $destino, $tipo, $qtd) {
            $dataMovimentacao = $this->resolveDataMovimentacao($dados['data_movimentacao'] ?? null);

            // 1) Ajuste de saldos na tabela `estoque`
            if ($origem) {
                // debita origem (valida disponível >= qtd)
                $this->debitarEstoque($variacaoId, (int) $origem, $qtd, $dataMovimentacao, $tipo, $dados);
            }
            if ($destino) {
                // credita destino (cria linha se não existir)
                $this->creditarEstoque($variacaoId, (int) $destino, $qtd, $dataMovimentacao, $tipo);
            }

            // 2) Persiste a movimentação (se algo falhar acima, tudo é revertido)
            /** @var EstoqueMovimentacao $mov */
            $mov = EstoqueMovimentacao::create([
                'id_variacao'         => $variacaoId,
                'id_deposito_origem'  => $origem ?? null,
                'id_deposito_destino' => $destino ?? null,
                'tipo'                => $tipo,
                'quantidade'          => $qtd,
                'id_usuario'          => $dados['id_usuario'] ?? null,
                'observacao'          => $dados['observacao'] ?? null,
                'data_movimentacao'   => $dataMovimentacao,

                // ✅ extras (não obrigatórios)
                'lote_id'        => $dados['lote_id'] ?? null,
                'ref_type'       => $dados['ref_type'] ?? null,
                'ref_id'         => $dados['ref_id'] ?? null,
                'pedido_id'      => $dados['pedido_id'] ?? null,
                'pedido_item_id' => $dados['pedido_item_id'] ?? null,
                'reserva_id'     => $dados['reserva_id'] ?? null,
            ]);

            return $mov->fresh(['variacao.produto', 'usuario', 'depositoOrigem', 'depositoDestino']);
        });
    }

    /**
     * Debita (–) saldo do depósito. Valida disponível (quantidade - reservas).
     */
    private function debitarEstoque(
        int $variacaoId,
        int $depositoId,
        int $qtd,
        Carbon $dataMovimentacao,
        string $tipo,
        array $dadosMovimentacao
    ): void
    {
        // trava a linha de estoque (se existir) para evitar corrida
        $row = DB::table('estoque') // <- ajuste o nome da tabela se necessário
        ->where('id_variacao', $variacaoId)
            ->where('id_deposito', $depositoId)
            ->lockForUpdate()
            ->first();

        if (!$row) {
            throw new InvalidArgumentException('Saldo inexistente no depósito de origem.');
        }

        $quantidadeAtual = (int) $row->quantidade;

        // Considera reservas em aberto no depósito
        $reservas = app(\App\Services\ReservaEstoqueService::class)
            ->reservasEmAbertoPorDeposito($variacaoId, $depositoId);

        $disponivel = $quantidadeAtual - (int) $reservas;
        if ($disponivel < $qtd) {
            throw new InvalidArgumentException("Saldo insuficiente. Disponível: {$disponivel}.");
        }

        $novaQtd = $quantidadeAtual - $qtd;

        $updatePayload = [
            'quantidade' => $novaQtd,
            'updated_at' => Carbon::now(),
        ];

        if ($novaQtd === 0) {
            $updatePayload['data_entrada_estoque_atual'] = null;
        }

        if ($this->isMovimentacaoVenda($tipo, $dadosMovimentacao)) {
            $updatePayload['ultima_venda_em'] = $dataMovimentacao;
        }

        DB::table('estoque')
            ->where('id_variacao', $variacaoId)
            ->where('id_deposito', $depositoId)
            ->update($updatePayload);
    }

    /**
     * Credita (+) saldo no depósito. Faz upsert se a linha não existir.
     */
    private function creditarEstoque(
        int $variacaoId,
        int $depositoId,
        int $qtd,
        Carbon $dataMovimentacao,
        string $tipo
    ): void
    {
        // tenta travar a linha se existir
        $row = DB::table('estoque') // <- ajuste o nome da tabela se necessário
        ->where('id_variacao', $variacaoId)
            ->where('id_deposito', $depositoId)
            ->lockForUpdate()
            ->first();

        if ($row) {
            $novaQtd = (int) $row->quantidade + $qtd;

            $updatePayload = [
                'quantidade' => $novaQtd,
                'updated_at' => Carbon::now(),
            ];

            if ($this->deveDefinirDataEntradaAtual($tipo, (int) $row->quantidade, $novaQtd)) {
                $updatePayload['data_entrada_estoque_atual'] = $dataMovimentacao;
            }

            DB::table('estoque')
                ->where('id_variacao', $variacaoId)
                ->where('id_deposito', $depositoId)
                ->update($updatePayload);

            return;
        }

        // não existe: cria
        $insertPayload = [
            'id_variacao' => $variacaoId,
            'id_deposito' => $depositoId,
            'quantidade'  => $qtd,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ];

        if ($this->deveDefinirDataEntradaAtual($tipo, 0, $qtd)) {
            $insertPayload['data_entrada_estoque_atual'] = $dataMovimentacao;
        }

        DB::table('estoque')->insert($insertPayload);
    }

    private function resolveDataMovimentacao(mixed $rawDate): Carbon
    {
        if ($rawDate instanceof CarbonInterface) {
            return Carbon::instance($rawDate);
        }

        if ($rawDate instanceof \DateTimeInterface) {
            return Carbon::instance($rawDate);
        }

        if ($rawDate === null || $rawDate === '') {
            return Carbon::now();
        }

        return Carbon::parse((string) $rawDate);
    }

    private function isMovimentacaoVenda(string $tipo, array $dadosMovimentacao): bool
    {
        if ($tipo === EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value) {
            return true;
        }

        if ($tipo !== EstoqueMovimentacaoTipo::SAIDA->value) {
            return false;
        }

        if (!empty($dadosMovimentacao['pedido_id']) || !empty($dadosMovimentacao['pedido_item_id'])) {
            return true;
        }

        return ($dadosMovimentacao['ref_type'] ?? null) === 'pedido';
    }

    private function isTipoEntradaParaDataEstoque(string $tipo): bool
    {
        return in_array($tipo, [
            EstoqueMovimentacaoTipo::ENTRADA->value,
            EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
        ], true);
    }

    private function deveDefinirDataEntradaAtual(string $tipo, int $saldoAnterior, int $saldoNovo): bool
    {
        return $this->isTipoEntradaParaDataEstoque($tipo)
            && $saldoAnterior === 0
            && $saldoNovo > 0;
    }

    /**
     * Registra ENTRADA em um depósito (ex.: início de reparo local).
     * Origem: externo/cliente (null) → Destino: depósitoEntradaId
     */
    public function registrarEntradaDeposito(
        int $variacaoId,
        int $depositoEntradaId,
        int $quantidade,
        ?int $usuarioId,
        ?string $observacao = null
    ): EstoqueMovimentacao {
        return $this->movimentar([
            'id_variacao'         => $variacaoId,
            'id_deposito_origem'  => null,
            'id_deposito_destino' => $depositoEntradaId,
            'tipo'                => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
            'quantidade'          => $quantidade,
            'id_usuario'          => $usuarioId,
            'observacao'          => $observacao,
        ]);
    }

    /**
     * Registra SAÍDA para o cliente (ex.: entrega após reparo).
     * Origem: depósitoSaidaId → Destino: cliente (null)
     */
    public function registrarSaidaEntregaCliente(
        int $variacaoId,
        int $depositoSaidaId,
        int $quantidade,
        ?int $usuarioId,
        ?string $observacao = null
    ): EstoqueMovimentacao {
        return $this->movimentar([
            'id_variacao'         => $variacaoId,
            'id_deposito_origem'  => $depositoSaidaId,
            'id_deposito_destino' => null,
            'tipo'                => EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            'quantidade'          => $quantidade,
            'id_usuario'          => $usuarioId,
            'observacao'          => $observacao,
        ]);
    }

    /**
     * (Opcional) Transfere entre depósitos internos.
     */
    public function registrarTransferencia(
        int $variacaoId,
        int $depositoOrigemId,
        int $depositoDestinoId,
        int $quantidade,
        ?int $usuarioId,
        ?string $observacao = null
    ): EstoqueMovimentacao {
        return $this->movimentar([
            'id_variacao'         => $variacaoId,
            'id_deposito_origem'  => $depositoOrigemId,
            'id_deposito_destino' => $depositoDestinoId,
            'tipo'                => EstoqueMovimentacaoTipo::TRANSFERENCIA->value,
            'quantidade'          => $quantidade,
            'id_usuario'          => $usuarioId,
            'observacao'          => $observacao,
        ]);
    }

    /**
     * Estorna uma movimentação existente, criando um novo registro inverso.
     * Não altera a movimentação original (auditável e imutável).
     */
    public function estornarMovimentacao(int $movimentacaoId, ?int $usuarioId = null, ?string $observacao = null): EstoqueMovimentacao
    {
        return DB::transaction(function () use ($movimentacaoId, $usuarioId, $observacao) {
            /** @var EstoqueMovimentacao $mov */
            $mov = EstoqueMovimentacao::query()->lockForUpdate()->findOrFail($movimentacaoId);

            if ($mov->tipo === EstoqueMovimentacaoTipo::ESTORNO->value) {
                throw new InvalidArgumentException('Não é permitido estornar uma movimentação de estorno.');
            }

            $jaEstornado = EstoqueMovimentacao::query()
                ->where('ref_type', 'estorno')
                ->where('ref_id', $mov->id)
                ->exists();

            if ($jaEstornado) {
                throw new InvalidArgumentException('Movimentação já estornada.');
            }

            $origem = $mov->id_deposito_origem;
            $destino = $mov->id_deposito_destino;

            if (!$origem && !$destino) {
                throw new RuntimeException('Movimentação inválida para estorno (origem e destino ausentes).');
            }

            // Inverte a direção
            $estornoOrigem = $destino ?: null;
            $estornoDestino = $origem ?: null;

            return $this->movimentar([
                'id_variacao'         => (int) $mov->id_variacao,
                'id_deposito_origem'  => $estornoOrigem,
                'id_deposito_destino' => $estornoDestino,
                'tipo'                => EstoqueMovimentacaoTipo::ESTORNO->value,
                'quantidade'          => (int) $mov->quantidade,
                'id_usuario'          => $usuarioId,
                'observacao'          => $observacao
                    ? "Estorno da movimentação #{$mov->id}. {$observacao}"
                    : "Estorno da movimentação #{$mov->id}.",
                'data_movimentacao'   => Carbon::now(),
                'ref_type'            => 'estorno',
                'ref_id'              => $mov->id,
            ]);
        });
    }

    public function registrarMovimentacaoLote(array $dados, int $usuarioId): array
    {
        $tipo = $dados['tipo'];
        $origem = $dados['deposito_origem_id'] ?? null;
        $destino = $dados['deposito_destino_id'] ?? null;
        $observacao = $dados['observacao'] ?? null;

        $consolidados = collect($dados['itens'])
            ->groupBy('variacao_id')
            ->map(fn($g) => (int) $g->sum('quantidade'));

        $movs = [];
        $transferencia = null;

        // um id único para agrupar tudo (serve pra PDF e rastreio)
        $loteId = (string) Str::uuid();

        DB::transaction(function () use (
            &$movs, &$transferencia,
            $tipo, $origem, $destino, $observacao, $usuarioId,
            $consolidados, $loteId
        ) {
            // 1) Se for transferência, cria o "documento"
            if ($tipo === 'transferencia') {
                $transferencia = EstoqueTransferencia::create([
                    'uuid' => $loteId,
                    'deposito_origem_id' => (int) $origem,
                    'deposito_destino_id' => (int) $destino,
                    'id_usuario' => $usuarioId,
                    'observacao' => $observacao,
                    'status' => 'concluida',
                    'total_itens' => $consolidados->count(),
                    'total_pecas' => (int) $consolidados->sum(),
                    'concluida_em' => now(),
                ]);

                // cria itens com snapshot de localização no depósito de origem
                foreach ($consolidados as $variacaoId => $qtd) {
                    $row = Estoque::query()
                        ->where('id_variacao', (int)$variacaoId)
                        ->where('id_deposito', (int)$origem)
                        ->first();

                    EstoqueTransferenciaItem::create([
                        'transferencia_id' => $transferencia->id,
                        'id_variacao' => (int) $variacaoId,
                        'quantidade' => (int) $qtd,
                        'corredor' => $row?->corredor,
                        'prateleira' => $row?->prateleira,
                        'nivel' => $row?->nivel,
                    ]);
                }
            }

            // 2) Registra as movimentações (debitando/creditando) e vincula ao lote/transferência
            foreach ($consolidados as $variacaoId => $qtd) {
                $dadosMov = [
                    'id_variacao' => (int) $variacaoId,
                    'quantidade'  => (int) $qtd,
                    'id_usuario'  => $usuarioId,
                    'observacao'  => $observacao,

                    // rastreio
                    'lote_id'   => $loteId,
                    'ref_type'  => $transferencia ? 'transferencia' : null,
                    'ref_id'    => $transferencia?->id,
                ];

                switch ($tipo) {
                    case 'entrada':
                        $dadosMov['id_deposito_destino'] = (int) $destino;
                        $dadosMov['tipo'] = 'entrada';
                        break;

                    case 'saida':
                        $dadosMov['id_deposito_origem'] = (int) $origem;
                        $dadosMov['tipo'] = 'saida';
                        break;

                    case 'transferencia':
                        $dadosMov['id_deposito_origem']  = (int) $origem;
                        $dadosMov['id_deposito_destino'] = (int) $destino;
                        $dadosMov['tipo'] = 'transferencia';
                        break;
                }

                $movs[] = $this->movimentar($dadosMov);
            }
        });

        return [
            'sucesso' => true,
            'mensagem' => 'Movimentação concluída com sucesso.',
            'total_itens' => $consolidados->count(),
            'total_pecas' => (int) $consolidados->sum(),
            'lote_id' => $loteId,
            'transferencia_id' => $transferencia?->id,
            'transferencia_pdf' => $transferencia
                ? route('estoque.transferencias.pdf', ['transferencia' => $transferencia->id])
                : null,

            'movimentacoes' => MovimentacaoResource::collection(collect($movs)),
        ];
    }

    public function registrarSaidaPedido(
        int $variacaoId,
        int $depositoSaidaId,
        int $quantidade,
        int $usuarioId,
        string $observacao,
        ?int $pedidoId = null,
        ?int $pedidoItemId = null,
        ?string $loteId = null,
        ?int $reservaId = null
    ): void {
        DB::transaction(function () use (
            $variacaoId, $depositoSaidaId, $quantidade, $usuarioId, $observacao,
            $pedidoId, $pedidoItemId, $loteId, $reservaId
        ) {
            // 1) Se não veio reservaId, tenta achar reserva ativa vinculada ao pedido/pedido_item
            if (!$reservaId && $pedidoId) {
                $query = EstoqueReserva::query()
                    ->where('pedido_id', $pedidoId)
                    ->where('status', 'ativa')
                    ->where('id_variacao', $variacaoId)
                    ->when($depositoSaidaId, fn($q) => $q->where('id_deposito', $depositoSaidaId))
                    ->when($pedidoItemId, fn($q) => $q->where('pedido_item_id', $pedidoItemId))
                    ->where(function ($q) {
                        $q->whereNull('data_expira')
                            ->orWhere('data_expira', '>', now());
                    })
                    ->orderBy('id', 'asc');

                /** @var EstoqueReserva|null $res */
                $res = $query->lockForUpdate()->first();

                if ($res) {
                    $reservaId = (int) $res->id;

                    // 1.1) Consome a reserva (total ou parcial)
                    $restante = max(0, (int)$res->quantidade - (int)$res->quantidade_consumida);
                    $consumir = min($restante, $quantidade);

                    if ($consumir > 0) {
                        $res->quantidade_consumida = (int)$res->quantidade_consumida + $consumir;

                        // Se consumiu tudo, marca como consumida
                        if ($res->quantidade_consumida >= (int)$res->quantidade) {
                            $res->status = 'consumida';
                        }

                        $res->id_usuario = $usuarioId;
                        $res->save();
                    }
                }
            }

            // 2) Movimenta (origem depósito → destino null) com refs preenchidas
            $this->movimentar([
                'id_variacao'         => $variacaoId,
                'id_deposito_origem'  => $depositoSaidaId,
                'id_deposito_destino' => null,
                'tipo'                => EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
                'quantidade'          => $quantidade,
                'id_usuario'          => $usuarioId,
                'observacao'          => $observacao,

                // ✅ rastreio
                'lote_id'        => $loteId,
                'pedido_id'      => $pedidoId,
                'pedido_item_id' => $pedidoItemId,
                'reserva_id'     => $reservaId,

                // ✅ referência genérica (se quiser padronizar)
                'ref_type' => $pedidoId ? 'pedido' : null,
                'ref_id'   => $pedidoId,
            ]);
        });
    }



}
