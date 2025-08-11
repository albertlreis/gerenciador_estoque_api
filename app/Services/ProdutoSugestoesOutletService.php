<?php

namespace App\Services;

use App\Models\ProdutoVariacao;
use App\Support\Configuracao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProdutoSugestoesOutletService
{
    /**
     * Sugestões de outlet por VARIAÇÃO.
     *
     * @param Request $request
     * @return array{
     *     dias_limite:int,
     *     itens: array<int, array{
     *         id:int,
     *         produto_id:int,
     *         referencia:string|null,
     *         nome_completo:string,
     *         quantidade_total:int,
     *         dias_em_estoque:int,
     *         preco:string|null,
     *         has_outlet_ativo:bool
     *     }>
     * }
     */
    public function listarPorVariacao(Request $request): array
    {
        $limite   = (int)$request->input('limite', 5);
        $deposito = $request->input('deposito'); // opcional
        $ordenar  = $request->input('ordenar', 'dias'); // dias|quantidade|nome|preco
        $ordem    = $request->input('ordem', 'desc');

        $diasLimite = Configuracao::getInt('dias_para_outlet', 120);

        // Subselect: quantidade total em estoque por variação (filtra por depósito se informado)
        $quantidadeSub = DB::table('estoque as e')
            ->selectRaw('COALESCE(SUM(e.quantidade), 0)')
            ->whereColumn('e.id_variacao', 'pv.id');

        if (!empty($deposito)) {
            $quantidadeSub->where('e.id_deposito', $deposito);
        }

        // Subselect: última movimentação por variação
        $ultimaMovSub = DB::table('estoque_movimentacoes as em')
            ->selectRaw('MAX(em.data_movimentacao)')
            ->whereColumn('em.id_variacao', 'pv.id');

        if (!empty($deposito)) {
            // considera origem OU destino
            $ultimaMovSub->where(function ($q) use ($deposito) {
                $q->where('em.id_deposito_origem', $deposito)
                    ->orWhere('em.id_deposito_destino', $deposito);
            });
        }

        // Subselect: primeira entrada registrada em estoque (fallback)
        $primeiraEntradaSub = DB::table('estoque as e2')
            ->selectRaw('MIN(e2.created_at)')
            ->whereColumn('e2.id_variacao', 'pv.id');

        if (!empty($deposito)) {
            $primeiraEntradaSub->where('e2.id_deposito', $deposito);
        }

        // Subselect: existe outlet ATIVO (quantidade_restante > 0) nesta variação?
        $hasOutletAtivoSub = DB::table('produto_variacao_outlets as pvo')
            ->selectRaw('COUNT(*) > 0')
            ->whereColumn('pvo.produto_variacao_id', 'pv.id')
            ->where('pvo.quantidade_restante', '>', 0);

        // DATEDIFF com fallback: última mov -> primeira entrada -> criado do produto
        $diasExprSql = "
        DATEDIFF(
            CURDATE(),
            COALESCE(
                ({$ultimaMovSub->toSql()}),
                ({$primeiraEntradaSub->toSql()}),
                p.created_at
            )
        )
    ";
        // Bindings das subqueries usadas em $diasExprSql
        $diasBindings = array_merge(
            $ultimaMovSub->getBindings(),
            $primeiraEntradaSub->getBindings()
        );

        // Query principal
        $query = DB::table('produto_variacoes as pv')
            ->join('produtos as p', 'p.id', '=', 'pv.produto_id')
            ->where('p.ativo', true)
            ->select([
                'pv.id',
                'pv.produto_id',
                'pv.referencia',
                'pv.nome',
                'pv.preco',
                'p.nome as produto_nome',
            ])
            // subqueries com bindings próprios: use selectSub (não precisa mergeBindings)
            ->selectSub($quantidadeSub, 'quantidade_total')
            ->selectRaw("$diasExprSql as dias_em_estoque", $diasBindings)
            ->selectSub($hasOutletAtivoSub, 'has_outlet_ativo')
            // filtros (tem estoque; parado >= limite; sem outlet ativo)
            ->having('quantidade_total', '>', 0)
            ->having('dias_em_estoque', '>=', $diasLimite)
            ->havingRaw('has_outlet_ativo = 0')
            ->limit($limite);

        // Ordenação
        switch ($ordenar) {
            case 'quantidade':
                $query->orderBy('quantidade_total', $ordem);
                break;
            case 'nome':
                // aproxima do "nome_completo": produto + nome da variação
                $query->orderBy('p.nome', $ordem)->orderBy('pv.nome', $ordem);
                break;
            case 'preco':
                $query->orderBy('pv.preco', $ordem);
                break;
            case 'dias':
            default:
                $query->orderBy('dias_em_estoque', $ordem);
        }

        $rows = $query->get();

        // Monta nome_completo usando accessor (produto + atributos)
        $variacoes = ProdutoVariacao::with(['produto', 'atributos'])
            ->whereIn('id', $rows->pluck('id'))
            ->get()
            ->keyBy('id');

        $itens = $rows->map(function ($r) use ($variacoes) {
            $v = $variacoes->get($r->id);
            $nomeCompleto = $v ? $v->nome_completo : trim(($r->produto_nome ?? '') . ' - ' . ($r->nome ?? ''));

            return [
                'id'               => (int)$r->id,
                'produto_id'       => (int)$r->produto_id,
                'referencia'       => $r->referencia,
                'nome_completo'    => $nomeCompleto,
                'quantidade_total' => (int)$r->quantidade_total,
                'dias_em_estoque'  => max(0, (int)$r->dias_em_estoque),
                'preco'            => $r->preco, // string|null conforme schema
                'has_outlet_ativo' => false,     // já filtrado por havingRaw('has_outlet_ativo = 0')
            ];
        })->values()->all();

        return [
            'dias_limite' => $diasLimite,
            'itens'       => $itens,
        ];
    }
}
