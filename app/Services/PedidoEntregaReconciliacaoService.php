<?php

namespace App\Services;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoReconciliacao;
use App\Models\PedidoReconciliacaoItem;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PedidoEntregaReconciliacaoService
{
    public const COLUNAS = [
        'lote_id',
        'tipo_registro',
        'pedido_id',
        'numero_externo',
        'pedido_item_id',
        'id_variacao',
        'referencia',
        'deposito_id',
        'classificacao',
        'quantidade_pendente',
        'acao',
        'saldo_sistema_snapshot',
        'saldo_fisico_confirmado',
        'confirmacao_documental',
        'confirmacao_fisica',
        'data_ocorrencia',
        'evidencia',
        'justificativa',
    ];

    public function __construct(
        private readonly EntregaProdutoService $entregas,
        private readonly EstoqueMovimentacaoService $movimentacoes,
        private readonly EstoqueAjusteService $ajustes,
    ) {}

    /** @return array{lote_id:string,linhas:int,arquivo:string} */
    public function exportar(string $arquivo): array
    {
        $loteId = (string) Str::uuid();
        $linhas = $this->linhasExportacao($loteId);

        $diretorio = dirname($arquivo);
        if (! is_dir($diretorio) && ! mkdir($diretorio, 0775, true) && ! is_dir($diretorio)) {
            throw new RuntimeException("Não foi possível criar o diretório {$diretorio}.");
        }

        $handle = fopen($arquivo, 'wb');
        if (! $handle) {
            throw new RuntimeException("Não foi possível abrir {$arquivo} para escrita.");
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::COLUNAS, ';');
            foreach ($linhas as $linha) {
                fputcsv($handle, array_map(fn (string $coluna) => $linha[$coluna] ?? '', self::COLUNAS), ';');
            }
        } finally {
            fclose($handle);
        }

        return ['lote_id' => $loteId, 'linhas' => count($linhas), 'arquivo' => $arquivo];
    }

    /** @return Collection<int,array<string,string>> */
    public function lerManifesto(string $arquivo): Collection
    {
        if (! is_file($arquivo)) {
            throw ValidationException::withMessages(['manifesto' => ["Arquivo não encontrado: {$arquivo}"]]);
        }

        $handle = fopen($arquivo, 'rb');
        if (! $handle) {
            throw ValidationException::withMessages(['manifesto' => ["Não foi possível ler: {$arquivo}"]]);
        }

        try {
            $cabecalho = fgetcsv($handle, 0, ';');
            if (! $cabecalho) {
                throw ValidationException::withMessages(['manifesto' => ['Manifesto vazio.']]);
            }
            $cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cabecalho[0]);
            if ($cabecalho !== self::COLUNAS) {
                throw ValidationException::withMessages([
                    'manifesto' => ['Cabeçalho inválido. Gere novamente o manifesto pelo comando.'],
                ]);
            }

            $linhas = collect();
            $numero = 1;
            while (($valores = fgetcsv($handle, 0, ';')) !== false) {
                $numero++;
                if (count($valores) === 1 && trim((string) $valores[0]) === '') {
                    continue;
                }
                if (count($valores) !== count(self::COLUNAS)) {
                    throw ValidationException::withMessages([
                        'manifesto' => ["Linha {$numero} possui quantidade incorreta de colunas."],
                    ]);
                }
                $linha = array_combine(self::COLUNAS, array_map(fn ($v) => trim((string) $v), $valores));
                $linha['_linha'] = (string) $numero;
                $linhas->push($linha);
            }

            return $linhas;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    public function analisar(Collection $linhas, ?int $usuarioId = null): array
    {
        $erros = [];
        $lotes = $linhas->pluck('lote_id')->filter()->unique()->values();
        if ($linhas->isEmpty()) {
            $erros[] = 'O manifesto não possui linhas.';
        }
        if ($lotes->count() !== 1 || ! Str::isUuid((string) $lotes->first())) {
            $erros[] = 'Todas as linhas devem possuir o mesmo lote_id UUID.';
        }
        if ($usuarioId !== null && ! Usuario::query()->whereKey($usuarioId)->exists()) {
            $erros[] = "Usuário {$usuarioId} não encontrado.";
        }

        foreach ($linhas as $linha) {
            $this->validarLinha($linha, $erros);
        }

        if ($erros === []) {
            $this->validarGruposFisicos($linhas, $erros);
        }

        $acoes = $linhas->groupBy('acao')->map(fn (Collection $grupo) => [
            'linhas' => $grupo->count(),
            'unidades' => (int) $grupo->sum(fn ($linha) => (int) $linha['quantidade_pendente']),
        ])->all();

        return [
            'valido' => $erros === [],
            'lote_id' => $lotes->count() === 1 ? (string) $lotes->first() : null,
            'linhas' => $linhas->count(),
            'pedidos' => $linhas->pluck('pedido_id')->filter()->unique()->count(),
            'acoes' => $acoes,
            'erros' => $erros,
        ];
    }

    /** @return array<string,mixed> */
    public function aplicar(Collection $linhas, int $usuarioId, string $confirmacao): array
    {
        $lotesCandidatos = $linhas->pluck('lote_id')->filter()->unique()->values();
        $loteCandidato = $lotesCandidatos->count() === 1 ? (string) $lotesCandidatos->first() : '';
        $pedidosCandidatos = $linhas->pluck('pedido_id')->filter()->unique()->values();
        $jaAplicadas = PedidoReconciliacao::query()
            ->whereIn('pedido_id', $pedidosCandidatos)
            ->where('idempotency_key', 'like', $loteCandidato.':%')
            ->where('status', 'aplicada')
            ->count();
        if (
            Str::isUuid($loteCandidato)
            && hash_equals($loteCandidato, $confirmacao)
            && $pedidosCandidatos->isNotEmpty()
            && $jaAplicadas === $pedidosCandidatos->count()
        ) {
            return [
                'valido' => true,
                'lote_id' => $loteCandidato,
                'linhas' => $linhas->count(),
                'pedidos' => $pedidosCandidatos->count(),
                'acoes' => [],
                'erros' => [],
                'ja_aplicado' => true,
            ];
        }

        $analise = $this->analisar($linhas, $usuarioId);
        if (! $analise['valido']) {
            throw ValidationException::withMessages(['manifesto' => $analise['erros']]);
        }

        $loteId = (string) $analise['lote_id'];
        if (! hash_equals($loteId, $confirmacao)) {
            throw ValidationException::withMessages([
                'confirmar' => ['A confirmação deve ser exatamente igual ao lote_id do manifesto.'],
            ]);
        }

        return DB::transaction(function () use ($linhas, $usuarioId, $loteId, $analise) {
            $this->bloquearEstadoDoLote($linhas);
            $resultados = [];

            foreach ($linhas->sortBy(fn ($linha) => sprintf('%010d:%010d', (int) $linha['pedido_id'], (int) $linha['pedido_item_id'])) as $linha) {
                $pedido = Pedido::query()->lockForUpdate()->findOrFail((int) $linha['pedido_id']);
                $reconciliacao = PedidoReconciliacao::query()->firstOrCreate(
                    ['idempotency_key' => "{$loteId}:{$pedido->id}"],
                    [
                        'pedido_id' => $pedido->id,
                        'usuario_id' => $usuarioId,
                        'fonte_verdade' => 'entrega_cliente',
                        'justificativa' => $linha['justificativa'],
                        'evidencia' => $linha['evidencia'],
                        'snapshot_json' => ['lote_id' => $loteId, 'linhas' => $linhas->where('pedido_id', (string) $pedido->id)->values()->all()],
                    ]
                );

                $resultado = $this->aplicarLinha($linha, $reconciliacao, $usuarioId, $loteId);
                $resultados[] = $resultado;
            }

            PedidoReconciliacao::query()
                ->where('idempotency_key', 'like', $loteId.':%')
                ->update(['status' => 'aplicada', 'aplicada_em' => now(), 'updated_at' => now()]);

            return [...$analise, 'ja_aplicado' => false, 'resultados' => $resultados];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function estornar(string $loteId, int $usuarioId, string $confirmacao): array
    {
        if (! Str::isUuid($loteId) || ! hash_equals($loteId, $confirmacao)) {
            throw ValidationException::withMessages(['confirmar' => ['Confirmação de lote inválida.']]);
        }
        if (! Usuario::query()->whereKey($usuarioId)->exists()) {
            throw ValidationException::withMessages(['usuario' => ['Usuário não encontrado.']]);
        }

        return DB::transaction(function () use ($loteId, $usuarioId) {
            $reconciliacoes = PedidoReconciliacao::query()
                ->where('idempotency_key', 'like', $loteId.':%')
                ->with(['itens' => fn ($query) => $query->orderByDesc('id')])
                ->lockForUpdate()
                ->get();
            if ($reconciliacoes->isEmpty()) {
                throw ValidationException::withMessages(['lote' => ['Lote aplicado não encontrado.']]);
            }

            $estornados = 0;
            foreach ($reconciliacoes as $reconciliacao) {
                foreach ($reconciliacao->itens as $detalhe) {
                    if ($detalhe->status === 'estornada') {
                        continue;
                    }
                    $resultado = $detalhe->resultado_json ?? [];
                    $entregaEventoId = (int) ($resultado['entrega_evento_id'] ?? 0);
                    $expedicaoEventoId = (int) ($resultado['expedicao_evento_id'] ?? 0);

                    if ($entregaEventoId) {
                        $this->entregas->estornarEvento($entregaEventoId, $usuarioId, "Rollback do lote {$loteId}");
                    }
                    if ($expedicaoEventoId) {
                        $this->entregas->estornarEvento($expedicaoEventoId, $usuarioId, "Rollback do lote {$loteId}");
                    } elseif ($detalhe->estoque_movimentacao_id) {
                        $this->movimentacoes->estornarMovimentacao(
                            (int) $detalhe->estoque_movimentacao_id,
                            $usuarioId,
                            "Rollback do lote {$loteId}"
                        );
                    }

                    $detalhe->update(['status' => 'estornada', 'estornada_em' => now()]);
                    $estornados++;
                }
                $reconciliacao->update(['status' => 'estornada']);
            }

            return ['lote_id' => $loteId, 'itens_estornados' => $estornados];
        }, 3);
    }

    /** @return array<int,array<string,string|int|null>> */
    private function linhasExportacao(string $loteId): array
    {
        $sql = file_get_contents(base_path('docs/auditorias/pedidos_entregues_sem_baixa.sql'));
        if ($sql === false) {
            throw new RuntimeException('Consulta de auditoria não encontrada.');
        }

        $linhas = collect(DB::select($sql))
            ->where('grupo_auditoria', 'CONFIRMADA')
            ->map(function ($divergencia) use ($loteId) {
                $pedidoItem = PedidoItem::query()->findOrFail((int) $divergencia->pedido_item_id);
                $entrega = ProdutoEntregaItem::query()
                    ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('pedido_item_id', $pedidoItem->id)
                    ->where('status', '<>', ProdutoEntregaItem::STATUS_CANCELADO)
                    ->first();
                $depositoId = $pedidoItem->id_deposito
                    ?: $entrega?->id_deposito_destino
                    ?: $entrega?->id_deposito_origem;
                $depositoId = $depositoId ? (int) $depositoId : null;
                $saldo = $depositoId
                    ? (int) Estoque::query()->where('id_variacao', $pedidoItem->id_variacao)->where('id_deposito', $depositoId)->value('quantidade')
                    : (int) Estoque::query()->where('id_variacao', $pedidoItem->id_variacao)->sum('quantidade');
                $deficit = max(
                    0,
                    (int) $divergencia->quantidade_vendida - (int) $divergencia->saida_liquida,
                    (int) $divergencia->quantidade_vendida - (int) $divergencia->quantidade_entregue
                );

                return [
                    'lote_id' => $loteId,
                    'tipo_registro' => 'PEDIDO',
                    'pedido_id' => (int) $divergencia->pedido_id,
                    'numero_externo' => $divergencia->numero_externo,
                    'pedido_item_id' => (int) $divergencia->pedido_item_id,
                    'id_variacao' => (int) $divergencia->id_variacao,
                    'referencia' => $divergencia->referencia,
                    'deposito_id' => $depositoId,
                    'classificacao' => $divergencia->classificacao,
                    'quantidade_pendente' => $deficit,
                    'acao' => $depositoId && $saldo >= $deficit
                        ? PedidoReconciliacaoItem::ACAO_BAIXAR_E_ENTREGAR
                        : PedidoReconciliacaoItem::ACAO_DOCUMENTAR_SEM_BAIXA,
                    'saldo_sistema_snapshot' => $saldo,
                    'saldo_fisico_confirmado' => '',
                    'confirmacao_documental' => 'NAO',
                    'confirmacao_fisica' => 'NAO',
                    'data_ocorrencia' => Carbon::parse($divergencia->data_status)->format('Y-m-d H:i:s'),
                    'evidencia' => '',
                    'justificativa' => '',
                ];
            })
            ->values();

        $tapete = DB::table('produto_variacoes as pv')
            ->join('depositos as d', 'd.nome', '=', DB::raw("'Loja'"))
            ->leftJoin('estoque as e', function ($join) {
                $join->on('e.id_variacao', '=', 'pv.id')->on('e.id_deposito', '=', 'd.id');
            })
            ->where('pv.referencia', '10.9884-4')
            ->select('pv.id as id_variacao', 'pv.referencia', 'd.id as deposito_id', DB::raw('COALESCE(e.quantidade, 0) saldo'))
            ->first();
        $pedido70 = Pedido::query()->where('numero_externo', '70')->first();
        $pedidoItem70 = $pedido70
            ? PedidoItem::query()->where('id_pedido', $pedido70->id)->where('id_variacao', $tapete?->id_variacao)->first()
            : null;

        if ($tapete && $pedido70 && $pedidoItem70 && (int) $tapete->saldo > 0) {
            $linhas->push([
                'lote_id' => $loteId,
                'tipo_registro' => 'AJUSTE_ESTOQUE',
                'pedido_id' => (int) $pedido70->id,
                'numero_externo' => $pedido70->numero_externo,
                'pedido_item_id' => (int) $pedidoItem70->id,
                'id_variacao' => (int) $tapete->id_variacao,
                'referencia' => $tapete->referencia,
                'deposito_id' => (int) $tapete->deposito_id,
                'classificacao' => 'SALDO_FANTASMA_CONSIGNACAO',
                'quantidade_pendente' => (int) $tapete->saldo,
                'acao' => PedidoReconciliacaoItem::ACAO_AJUSTAR_SALDO,
                'saldo_sistema_snapshot' => (int) $tapete->saldo,
                'saldo_fisico_confirmado' => '',
                'confirmacao_documental' => 'NAO',
                'confirmacao_fisica' => 'NAO',
                'data_ocorrencia' => now()->format('Y-m-d H:i:s'),
                'evidencia' => 'Movimentação #6554 reintroduziu o item após a devolução da consignação do Pedido 69; o Pedido 70 já possui saída integral.',
                'justificativa' => 'Remover o saldo fantasma do tapete 10.9884-4 após conferência física no depósito Loja.',
            ]);
        }

        return $linhas->all();
    }

    /** @param array<string,string> $linha @param array<int,string> $erros */
    private function validarLinha(array $linha, array &$erros): void
    {
        $prefixo = "Linha {$linha['_linha']}";
        $acoes = [
            PedidoReconciliacaoItem::ACAO_BAIXAR_E_ENTREGAR,
            PedidoReconciliacaoItem::ACAO_DOCUMENTAR_SEM_BAIXA,
            PedidoReconciliacaoItem::ACAO_AJUSTAR_SALDO,
        ];
        if (! in_array($linha['acao'], $acoes, true)) {
            $erros[] = "{$prefixo}: ação inválida.";

            return;
        }
        if (! $this->confirmado($linha['confirmacao_documental']) || ! $this->confirmado($linha['confirmacao_fisica'])) {
            $erros[] = "{$prefixo}: confirmações documental e física são obrigatórias.";
        }
        if (mb_strlen($linha['evidencia']) < 10 || mb_strlen($linha['justificativa']) < 10) {
            $erros[] = "{$prefixo}: evidência e justificativa devem possuir ao menos 10 caracteres.";
        }
        if ((int) $linha['pedido_id'] <= 0 || (int) $linha['pedido_item_id'] <= 0 || (int) $linha['id_variacao'] <= 0) {
            $erros[] = "{$prefixo}: vínculos de pedido/item/variação são obrigatórios.";
        }
        if ((int) $linha['quantidade_pendente'] <= 0) {
            $erros[] = "{$prefixo}: quantidade pendente deve ser positiva.";
        }
        if ($linha['saldo_fisico_confirmado'] === '' || (int) $linha['saldo_fisico_confirmado'] < 0) {
            $erros[] = "{$prefixo}: informe o saldo físico confirmado.";
        }
        if ($linha['deposito_id'] === '' && $linha['acao'] !== PedidoReconciliacaoItem::ACAO_DOCUMENTAR_SEM_BAIXA) {
            $erros[] = "{$prefixo}: a ação exige depósito.";
        }
        try {
            Carbon::parse($linha['data_ocorrencia']);
        } catch (\Throwable) {
            $erros[] = "{$prefixo}: data de ocorrência inválida.";
        }

        $pedido = Pedido::query()->with('statusAtual')->find((int) $linha['pedido_id']);
        $item = PedidoItem::query()->find((int) $linha['pedido_item_id']);
        if (! $pedido || ! $item || (int) $item->id_pedido !== (int) $pedido->id || (int) $item->id_variacao !== (int) $linha['id_variacao']) {
            $erros[] = "{$prefixo}: pedido/item/variação não conferem.";

            return;
        }
        $referenciaAtual = (string) $item->variacao()->value('referencia');
        if ($referenciaAtual !== $linha['referencia']) {
            $erros[] = "{$prefixo}: referência da variação mudou desde a exportação.";
        }
        $entregasAtivas = ProdutoEntregaItem::query()
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('pedido_item_id', $item->id)
            ->where('status', '<>', ProdutoEntregaItem::STATUS_CANCELADO)
            ->get();
        if ($entregasAtivas->count() > 1) {
            $erros[] = "{$prefixo}: existem múltiplos registros centrais de entrega para o mesmo item.";
        }
        $status = $pedido->statusAtual?->getRawOriginal('status') ?? $pedido->statusAtual?->status;
        if (! in_array($status, [PedidoStatus::ENTREGA_CLIENTE->value, PedidoStatus::FINALIZADO->value], true)) {
            $erros[] = "{$prefixo}: o pedido não está entregue/finalizado.";
        }
        if ((string) ($pedido->numero_externo ?? '') !== (string) $linha['numero_externo']) {
            $erros[] = "{$prefixo}: número externo mudou desde a exportação.";
        }
        $depositosPermitidos = collect([
            $item->id_deposito,
            $entregasAtivas->first()?->id_deposito_destino,
            $entregasAtivas->first()?->id_deposito_origem,
        ])->filter()->map(fn ($id) => (int) $id)->unique();
        if (
            $linha['deposito_id'] !== ''
            && ! $depositosPermitidos->contains((int) $linha['deposito_id'])
            && $linha['acao'] !== PedidoReconciliacaoItem::ACAO_AJUSTAR_SALDO
        ) {
            $erros[] = "{$prefixo}: depósito do item mudou desde a exportação.";
        }

        $deficit = $this->deficitAtual($item);
        if ($linha['acao'] !== PedidoReconciliacaoItem::ACAO_AJUSTAR_SALDO && (int) $linha['quantidade_pendente'] !== $deficit) {
            $erros[] = "{$prefixo}: divergência mudou (manifesto {$linha['quantidade_pendente']}, atual {$deficit}).";
        }
        if ($linha['acao'] === PedidoReconciliacaoItem::ACAO_AJUSTAR_SALDO && $linha['referencia'] !== '10.9884-4') {
            $erros[] = "{$prefixo}: ajuste de saldo fora do tapete autorizado.";
        }
    }

    /** @param array<int,string> $erros */
    private function validarGruposFisicos(Collection $linhas, array &$erros): void
    {
        foreach ($linhas->groupBy(fn ($linha) => $linha['id_variacao'].':'.($linha['deposito_id'] ?: 'todos')) as $chave => $grupo) {
            $fisicos = $grupo->pluck('saldo_fisico_confirmado')->unique();
            $snapshots = $grupo->pluck('saldo_sistema_snapshot')->unique();
            if ($fisicos->count() !== 1 || $snapshots->count() !== 1) {
                $erros[] = "Grupo {$chave}: saldos repetidos devem ser idênticos.";

                continue;
            }
            $snapshot = (int) $snapshots->first();
            $fisico = (int) $fisicos->first();
            $depositoId = (int) $grupo->first()['deposito_id'];
            $variacaoId = (int) $grupo->first()['id_variacao'];
            $saldoAtual = $depositoId
                ? (int) Estoque::query()->where('id_variacao', $variacaoId)->where('id_deposito', $depositoId)->value('quantidade')
                : (int) Estoque::query()->where('id_variacao', $variacaoId)->sum('quantidade');
            if ($saldoAtual !== $snapshot) {
                $erros[] = "Grupo {$chave}: saldo mudou desde a exportação ({$snapshot} -> {$saldoAtual}).";

                continue;
            }

            $baixa = (int) $grupo
                ->whereIn('acao', [PedidoReconciliacaoItem::ACAO_BAIXAR_E_ENTREGAR, PedidoReconciliacaoItem::ACAO_AJUSTAR_SALDO])
                ->sum(fn ($linha) => (int) $linha['quantidade_pendente']);
            if ($snapshot - $fisico !== $baixa) {
                $erros[] = "Grupo {$chave}: diferença físico/sistema não explica a baixa ({$snapshot}-{$fisico} != {$baixa}).";
            }

            if ($depositoId && $baixa > 0) {
                $pedidosDoGrupo = $grupo->pluck('pedido_id')->map(fn ($id) => (int) $id)->all();
                $reservasTerceiros = (int) EstoqueReserva::query()
                    ->where('id_variacao', $variacaoId)
                    ->where('id_deposito', $depositoId)
                    ->where('status', 'ativa')
                    ->where(fn ($query) => $query->whereNull('data_expira')->orWhere('data_expira', '>', now()))
                    ->where(function ($query) use ($pedidosDoGrupo) {
                        $query->whereNull('pedido_id')->orWhereNotIn('pedido_id', $pedidosDoGrupo);
                    })
                    ->sum(DB::raw('GREATEST(0, quantidade - quantidade_consumida)'));
                if ($snapshot - $reservasTerceiros < $baixa) {
                    $erros[] = "Grupo {$chave}: a baixa consumiria reserva de outro pedido.";
                }
            }
        }
    }

    private function deficitAtual(PedidoItem $item): int
    {
        $entregue = (int) ProdutoEntregaItem::query()
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('pedido_item_id', $item->id)
            ->where('status', '<>', ProdutoEntregaItem::STATUS_CANCELADO)
            ->sum('quantidade_entregue');
        $saida = $this->saidaLiquida($item);

        return max(0, (int) $item->quantidade - $entregue, (int) $item->quantidade - $saida);
    }

    private function saidaLiquida(PedidoItem $item): int
    {
        $movimentos = EstoqueMovimentacao::query()
            ->whereIn('tipo', [EstoqueMovimentacaoTipo::SAIDA->value, EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value])
            ->where('pedido_item_id', $item->id)
            ->get();
        $total = $movimentos->sum('quantidade');
        $estornado = (int) EstoqueMovimentacao::query()
            ->where('tipo', EstoqueMovimentacaoTipo::ESTORNO->value)
            ->where('ref_type', 'estorno')
            ->whereIn('ref_id', $movimentos->pluck('id'))
            ->sum('quantidade');

        if ($movimentos->isEmpty()) {
            $linhasVariacao = PedidoItem::query()
                ->where('id_pedido', $item->id_pedido)
                ->where('id_variacao', $item->id_variacao)
                ->count();
            if ($linhasVariacao === 1) {
                $legados = EstoqueMovimentacao::query()
                    ->whereIn('tipo', [EstoqueMovimentacaoTipo::SAIDA->value, EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value])
                    ->whereNull('pedido_item_id')
                    ->where('id_variacao', $item->id_variacao)
                    ->where(function ($query) use ($item) {
                        $query->where('pedido_id', $item->id_pedido)
                            ->orWhere(fn ($sub) => $sub->where('ref_type', 'pedido')->where('ref_id', $item->id_pedido));
                    })
                    ->get();
                $total += $legados->sum('quantidade');
                $estornado += (int) EstoqueMovimentacao::query()
                    ->where('tipo', EstoqueMovimentacaoTipo::ESTORNO->value)
                    ->where('ref_type', 'estorno')
                    ->whereIn('ref_id', $legados->pluck('id'))
                    ->sum('quantidade');
            }
        }

        return max(0, (int) $total - $estornado);
    }

    private function bloquearEstadoDoLote(Collection $linhas): void
    {
        Pedido::query()->whereIn('id', $linhas->pluck('pedido_id')->map(fn ($id) => (int) $id))->orderBy('id')->lockForUpdate()->get();
        PedidoItem::query()->whereIn('id', $linhas->pluck('pedido_item_id')->map(fn ($id) => (int) $id))->orderBy('id')->lockForUpdate()->get();
        foreach ($linhas->filter(fn ($linha) => $linha['deposito_id'] !== '')->sortBy(fn ($linha) => $linha['id_variacao'].':'.$linha['deposito_id'])->unique(fn ($linha) => $linha['id_variacao'].':'.$linha['deposito_id']) as $linha) {
            Estoque::query()->where('id_variacao', (int) $linha['id_variacao'])->where('id_deposito', (int) $linha['deposito_id'])->lockForUpdate()->first();
        }
        $analise = $this->analisar($linhas);
        if (! $analise['valido']) {
            throw ValidationException::withMessages(['manifesto' => $analise['erros']]);
        }
    }

    /** @param array<string,string> $linha @return array<string,mixed> */
    private function aplicarLinha(array $linha, PedidoReconciliacao $reconciliacao, int $usuarioId, string $loteId): array
    {
        $pedidoItem = PedidoItem::query()->lockForUpdate()->findOrFail((int) $linha['pedido_item_id']);
        $depositoId = $linha['deposito_id'] !== '' ? (int) $linha['deposito_id'] : null;
        $saldoAntes = $depositoId
            ? (int) Estoque::query()->where('id_variacao', $pedidoItem->id_variacao)->where('id_deposito', $depositoId)->value('quantidade')
            : (int) Estoque::query()->where('id_variacao', $pedidoItem->id_variacao)->sum('quantidade');

        $detalhe = PedidoReconciliacaoItem::query()->firstOrCreate(
            [
                'pedido_reconciliacao_id' => $reconciliacao->id,
                'pedido_item_id' => $pedidoItem->id,
                'acao' => $linha['acao'],
            ],
            [
                'classificacao_original' => $linha['classificacao'],
                'id_variacao' => $pedidoItem->id_variacao,
                'id_deposito' => $depositoId,
                'quantidade' => (int) $linha['quantidade_pendente'],
                'saldo_sistema_antes' => $saldoAntes,
                'saldo_fisico_confirmado' => (int) $linha['saldo_fisico_confirmado'],
            ]
        );
        if ($detalhe->status === 'aplicada') {
            return ['detalhe_id' => $detalhe->id, 'status' => 'ja_aplicada'];
        }

        $resultado = [];
        if ($linha['acao'] === PedidoReconciliacaoItem::ACAO_AJUSTAR_SALDO) {
            $ajuste = $this->ajustes->ajustarSaldoFinal(
                (int) $linha['id_variacao'],
                (int) $depositoId,
                (int) $linha['saldo_fisico_confirmado'],
                $usuarioId,
                $linha['justificativa'].' '.$linha['evidencia'],
                $loteId,
                'pedido_reconciliacao',
                (int) $detalhe->id
            );
            $detalhe->estoque_movimentacao_id = $ajuste['movimentacao']->id;
            $resultado['movimentacao_id'] = $ajuste['movimentacao']->id;
        } else {
            $pedido = Pedido::query()->findOrFail($pedidoItem->id_pedido);
            $entrega = ProdutoEntregaItem::query()
                ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                ->where('pedido_item_id', $pedidoItem->id)
                ->first();
            if (! $entrega) {
                $this->entregas->criarDemandaPedido($pedido, $usuarioId, false);
                $entrega = ProdutoEntregaItem::query()
                    ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('pedido_item_id', $pedidoItem->id)
                    ->firstOrFail();
            }
            $detalhe->produto_entrega_item_id = $entrega->id;
            $ocorridoEm = Carbon::parse($linha['data_ocorrencia']);
            $metadata = [
                'confirmacao_documental' => true,
                'confirmacao_fisica' => true,
                'evidencia' => $linha['evidencia'],
                'reconciliacao_id' => $reconciliacao->id,
                'lote_id' => $loteId,
            ];

            if ($linha['acao'] === PedidoReconciliacaoItem::ACAO_BAIXAR_E_ENTREGAR) {
                $baixa = $this->entregas->reconciliarBaixaEntrega(
                    $entrega,
                    (int) $depositoId,
                    (int) $linha['quantidade_pendente'],
                    $usuarioId,
                    $linha['justificativa'],
                    $loteId,
                    "reconciliacao:{$loteId}:item:{$pedidoItem->id}:expedir",
                    $ocorridoEm,
                    $metadata
                );
                $detalhe->estoque_movimentacao_id = $baixa['movimentacao']->id;
                $detalhe->produto_entrega_evento_id = $baixa['evento']->id;
                $resultado['movimentacao_id'] = $baixa['movimentacao']->id;
                $resultado['expedicao_evento_id'] = $baixa['evento']->id;
                $entrega = $baixa['item'];
            }

            $pendenteEntrega = max(0, (int) $entrega->quantidade_total - (int) $entrega->quantidade_entregue);
            if ($pendenteEntrega > 0) {
                $quantidadeEntrega = min((int) $linha['quantidade_pendente'], $pendenteEntrega);
                $this->entregas->entregarItem(
                    $entrega,
                    $quantidadeEntrega,
                    $usuarioId,
                    $linha['justificativa'],
                    "reconciliacao:{$loteId}:item:{$pedidoItem->id}:entregar",
                    $linha['acao'] === PedidoReconciliacaoItem::ACAO_DOCUMENTAR_SEM_BAIXA,
                    $ocorridoEm,
                    [...$metadata, 'confirmado_sem_saldo' => $linha['acao'] === PedidoReconciliacaoItem::ACAO_DOCUMENTAR_SEM_BAIXA]
                );
                $eventoEntrega = ProdutoEntregaEvento::query()
                    ->where('idempotency_key', "reconciliacao:{$loteId}:item:{$pedidoItem->id}:entregar")
                    ->firstOrFail();
                $resultado['entrega_evento_id'] = $eventoEntrega->id;
                $detalhe->produto_entrega_evento_id ??= $eventoEntrega->id;
            }
        }

        $saldoDepois = $depositoId
            ? (int) Estoque::query()->where('id_variacao', $pedidoItem->id_variacao)->where('id_deposito', $depositoId)->value('quantidade')
            : (int) Estoque::query()->where('id_variacao', $pedidoItem->id_variacao)->sum('quantidade');
        $detalhe->fill([
            'saldo_sistema_depois' => $saldoDepois,
            'status' => 'aplicada',
            'resultado_json' => $resultado,
            'aplicada_em' => now(),
        ])->save();

        return ['detalhe_id' => $detalhe->id, 'status' => 'aplicada', ...$resultado];
    }

    private function confirmado(string $valor): bool
    {
        return in_array(Str::lower($valor), ['1', 'sim', 'true', 'yes'], true);
    }
}
