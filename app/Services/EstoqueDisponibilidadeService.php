<?php

namespace App\Services;

use App\Models\Estoque;
use Illuminate\Support\Facades\DB;

/**
 * Serviço de leitura de disponibilidade de estoque.
 *
 * Responsável por calcular a quantidade "realmente disponível"
 * (saldo - reservas) de uma variação em um depósito específico (ou em todos).
 */
final class EstoqueDisponibilidadeService
{
    public function __construct(
        private readonly ReservaEstoqueService $reservas
    ) {}

    /**
     * Retorna a quantidade disponível (saldo - reservas) para uma variação e depósito.
     *
     * @param  int|null  $depositoId  Se null, soma de todos os depósitos.
     */
    public function getDisponivel(int $variacaoId, ?int $depositoId): int
    {
        $saldo = Estoque::query()
            ->where('id_variacao', $variacaoId)
            ->when($depositoId, fn ($q) => $q->where('id_deposito', $depositoId))
            ->sum('quantidade');

        $reservado = $this->reservas->reservasEmAbertoPorDeposito($variacaoId, $depositoId);

        return (int) $saldo - (int) $reservado;
    }

    /**
     * Lista somente depositos com saldo liquido disponivel para a variacao.
     *
     * @return array<int,array{id:int,nome:string,quantidade_disponivel:int}>
     */
    public function getDisponiveisPorDeposito(int $variacaoId): array
    {
        $reservas = DB::table('estoque_reservas')
            ->select('id_deposito')
            ->selectRaw('SUM(GREATEST(0, quantidade - quantidade_consumida)) AS quantidade_reservada')
            ->where('id_variacao', $variacaoId)
            ->where('status', 'ativa')
            ->where(function ($query) {
                $query->whereNull('data_expira')->orWhere('data_expira', '>', now());
            })
            ->groupBy('id_deposito');

        return DB::table('estoque as e')
            ->join('depositos as d', 'd.id', '=', 'e.id_deposito')
            ->leftJoinSub($reservas, 'r', fn ($join) => $join->on('r.id_deposito', '=', 'e.id_deposito'))
            ->where('e.id_variacao', $variacaoId)
            ->groupBy('d.id', 'd.nome')
            ->select('d.id', 'd.nome')
            ->selectRaw('GREATEST(0, SUM(e.quantidade) - COALESCE(MAX(r.quantidade_reservada), 0)) AS quantidade_disponivel')
            ->havingRaw('GREATEST(0, SUM(e.quantidade) - COALESCE(MAX(r.quantidade_reservada), 0)) > 0')
            ->orderBy('d.nome')
            ->get()
            ->map(fn ($deposito) => [
                'id' => (int) $deposito->id,
                'nome' => (string) $deposito->nome,
                'quantidade_disponivel' => (int) $deposito->quantidade_disponivel,
            ])
            ->all();
    }
}
