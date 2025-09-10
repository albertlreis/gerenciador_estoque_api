<?php

namespace App\Services;

use App\DTOs\FiltroMovimentacaoEstoqueDTO;
use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\EstoqueMovimentacao;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
     * Movimenta estoque: valida e persiste a movimentação.
     * OBS: Ajuste aqui se você atualiza saldos em outra tabela.
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

        // Persistência e (se aplicável) ajuste de saldos em transação
        return DB::transaction(function () use ($dados) {
            /** @var EstoqueMovimentacao $mov */
            $mov = EstoqueMovimentacao::create([
                'id_variacao'         => (int) $dados['id_variacao'],
                'id_deposito_origem'  => $dados['id_deposito_origem'] ?? null,
                'id_deposito_destino' => $dados['id_deposito_destino'] ?? null,
                'tipo'                => (string) $dados['tipo'],
                'quantidade'          => (int) $dados['quantidade'],
                'id_usuario'          => $dados['usuario_id'] ?? null,
                'observacao'          => $dados['observacao'] ?? null,
                'data_movimentacao'   => Carbon::now(),
            ]);

            return $mov->fresh(['variacao.produto', 'usuario', 'depositoOrigem', 'depositoDestino']);
        });
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

}
