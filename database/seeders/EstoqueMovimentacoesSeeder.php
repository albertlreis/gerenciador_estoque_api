<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EstoqueMovimentacoesSeeder extends Seeder
{
    /**
     * @throws \Random\RandomException
     */
    public function run(): void
    {
        $now = Carbon::now();

        // Lê dias limite da tabela de configuracoes (fallback 120)
        $diasLimite = (int) (DB::table('configuracoes')
            ->where('chave', 'dias_para_outlet')
            ->value('valor') ?? 120);

        // Opcional: limpar movimentações anteriores para estados reproduzíveis
        // (se isso for um problema em outros ambientes, troque por delete condicional)
        DB::table('estoque_movimentacoes')->truncate();

        // Usuário "qualquer" para vincular às movimentações
        $usuarioId = DB::table('acesso_usuarios')->value('id');

        // Vamos buscar o estoque e agrupar por variação para ter visão dos depósitos
        $estoquePorVariacao = DB::table('estoque')
            ->select('id_variacao', 'id_deposito', 'quantidade')
            ->orderBy('id_variacao')
            ->get()
            ->groupBy('id_variacao');

        foreach ($estoquePorVariacao as $idVariacao => $registrosDepositos) {
            // Escolhe um cenário para esta variação:
            // 0..49 → PARADO (50%), 50..84 => RECENTE (35%), 85..99 → TRANSFERÊNCIA RECENTE (15%)
            $sorteio = random_int(0, 99);

            if ($sorteio < 50) {
                // ===========================
                // CENÁRIO 1: PARADO (aparece na sugestão)
                // Última movimentação ANTES de diasLimite (ex.: diasLimite + 5..40)
                // ===========================
                $diasAtras = $diasLimite + random_int(5, 40);
                $dataMov = $now->copy()->subDays($diasAtras);

                foreach ($registrosDepositos as $reg) {
                    // Entrada inicial antiga
                    $this->inserirMov(
                        idVariacao: $idVariacao,
                        idOrigem: null,
                        idDestino: $reg->id_deposito,
                        idUsuario: $usuarioId,
                        tipo: 'entrada',
                        quantidade: max(1, (int)$reg->quantidade), // garante >= 1
                        dataMov: $dataMov,
                        observacao: 'Entrada antiga para simular estoque parado'
                    );
                }

                // Garante que quantidade em estoque da variação seja ≥1 (caso vindo de seed anterior tenha zerado)
                $qtdTotal = DB::table('estoque')->where('id_variacao', $idVariacao)->sum('quantidade');
                if ($qtdTotal <= 0) {
                    // adiciona 1 unidade no primeiro depósito para habilitar outlet
                    $primeiro = $registrosDepositos->first();
                    DB::table('estoque')->where('id_variacao', $idVariacao)
                        ->where('id_deposito', $primeiro->id_deposito)
                        ->update(['quantidade' => DB::raw('GREATEST(quantidade, 1)')]);

                    $this->inserirMov(
                        idVariacao: $idVariacao,
                        idOrigem: null,
                        idDestino: $primeiro->id_deposito,
                        idUsuario: $usuarioId,
                        tipo: 'entrada',
                        quantidade: 1,
                        dataMov: $dataMov,
                        observacao: 'Correção para garantir >=1 em estoque (cenário parado)'
                    );
                }

            } elseif ($sorteio < 85) {
                // ===========================
                // CENÁRIO 2: RECENTE (NÃO aparece na sugestão)
                // Última movimentação dentro da janela <= diasLimite-3 (ex.: 3..(diasLimite-3))
                // ===========================
                $diasAtras = random_int(3, max(3, $diasLimite - 3));
                $dataMovRecente = $now->copy()->subDays($diasAtras);

                foreach ($registrosDepositos as $reg) {
                    // Entrada mais antiga...
                    $this->inserirMov(
                        idVariacao: $idVariacao,
                        idOrigem: null,
                        idDestino: $reg->id_deposito,
                        idUsuario: $usuarioId,
                        tipo: 'entrada',
                        quantidade: max(1, (int)$reg->quantidade),
                        dataMov: $now->copy()->subDays($diasLimite + random_int(10, 60)),
                        observacao: 'Entrada base para variação com giro recente' // bem antes do limite
                    );

                    // ... Seguida de uma saída/transferência recente para atualizar o "última movimentação"
                    $saidaQtd = max(1, (int)floor($reg->quantidade * 0.2));
                    $this->inserirMov(
                        idVariacao: $idVariacao,
                        idOrigem: $reg->id_deposito,
                        idDestino: null,
                        idUsuario: $usuarioId,
                        tipo: 'saida',
                        quantidade: $saidaQtd,
                        dataMov: $dataMovRecente,
                        observacao: 'Saída recente simulada (mantém variação fora da sugestão)'
                    );
                }

            } else {
                // ===========================
                // CENÁRIO 3: TRANSFERÊNCIA RECENTE (NÃO aparece na sugestão)
                // Última movimentação = transferência dentro da janela recente (<= diasLimite-2)
                // ===========================
                $diasAtras = random_int(2, max(2, $diasLimite - 2));
                $dataMovRecente = $now->copy()->subDays($diasAtras);

                // Se só houver um depósito, criaremos mesmo assim uma "transferência" sem mudar estoque real
                $origem = $registrosDepositos->first()->id_deposito;
                $destino = $registrosDepositos->count() > 1
                    ? $registrosDepositos->slice(1, 1)->first()->id_deposito
                    : $origem;

                // Entrada antiga para base
                foreach ($registrosDepositos as $reg) {
                    $this->inserirMov(
                        idVariacao: $idVariacao,
                        idOrigem: null,
                        idDestino: $reg->id_deposito,
                        idUsuario: $usuarioId,
                        tipo: 'entrada',
                        quantidade: max(1, (int)$reg->quantidade),
                        dataMov: $now->copy()->subDays($diasLimite + random_int(15, 70)),
                        observacao: 'Entrada base (transferência recente)'
                    );
                }

                // Transferência recente
                $this->inserirMov(
                    idVariacao: $idVariacao,
                    idOrigem: $origem,
                    idDestino: $destino,
                    idUsuario: $usuarioId,
                    tipo: 'transferencia',
                    quantidade: random_int(1, 5),
                    dataMov: $dataMovRecente,
                    observacao: 'Transferência recente simulada (mantém variação fora da sugestão)'
                );
            }
        }
    }

    private function inserirMov(
        int $idVariacao,
        ?int $idOrigem,
        ?int $idDestino,
        ?int $idUsuario,
        string $tipo,
        int $quantidade,
        Carbon $dataMov,
        string $observacao = ''
    ): void {
        DB::table('estoque_movimentacoes')->insert([
            'id_variacao'         => $idVariacao,
            'id_deposito_origem'  => $idOrigem,
            'id_deposito_destino' => $idDestino,
            'id_usuario'          => $idUsuario,
            'tipo'                => $tipo,
            'quantidade'          => $quantidade,
            'observacao'          => $observacao,
            'data_movimentacao'   => $dataMov,
            'created_at'          => Carbon::now(),
            'updated_at'          => Carbon::now(),
        ]);
    }
}
