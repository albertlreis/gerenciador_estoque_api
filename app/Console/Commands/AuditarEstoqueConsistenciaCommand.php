<?php

namespace App\Console\Commands;

use App\Enums\EstoqueMovimentacaoTipo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditarEstoqueConsistenciaCommand extends Command
{
    protected $signature = 'estoque:auditar-consistencia {--json : Retorna o resumo em JSON}';

    protected $description = 'Executa consultas somente leitura para encontrar inconsistências de estoque.';

    public function handle(): int
    {
        $resultado = [
            'saldos_negativos' => $this->count(
                'select count(*) as total from estoque where quantidade < 0'
            ),
            'reservas_consumo_invalido' => $this->count(
                'select count(*) as total from estoque_reservas where quantidade <= 0 or quantidade_consumida > quantidade'
            ),
            'reservas_ativas_maiores_que_saldo' => $this->count(
                "select count(*) as total
                   from (
                         select r.id_variacao,
                                r.id_deposito,
                                sum(greatest(0, r.quantidade - r.quantidade_consumida)) as reservado,
                                coalesce(e.quantidade, 0) as saldo
                           from estoque_reservas r
                      left join estoque e
                             on e.id_variacao = r.id_variacao
                            and (e.id_deposito <=> r.id_deposito)
                          where r.status = 'ativa'
                            and (r.data_expira is null or r.data_expira > now())
                       group by r.id_variacao, r.id_deposito, e.quantidade
                         having reservado > saldo
                        ) inconsistencias"
            ),
            'movimentacoes_invalidas' => $this->countMovimentacoesInvalidas(),
            'estoque_divergente_do_livro' => $this->countEstoqueDivergenteDoLivro(),
            'transferencias_divergentes' => $this->countTransferenciasDivergentes(),
        ];

        $resultado['total_inconsistencias'] = array_sum($resultado);

        if ($this->option('json')) {
            $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $resultado['total_inconsistencias'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Verificação', 'Ocorrências'], collect($resultado)->map(
            fn ($total, $nome) => [$nome, $total]
        )->all());

        if ($resultado['total_inconsistencias'] > 0) {
            $this->warn('Foram encontradas inconsistências. Rode com --json ou inspecione as consultas do comando para amostras detalhadas.');
            return self::FAILURE;
        }

        $this->info('Nenhuma inconsistência de estoque encontrada.');
        return self::SUCCESS;
    }

    private function count(string $sql, array $bindings = []): int
    {
        return (int) (DB::selectOne($sql, $bindings)->total ?? 0);
    }

    private function countMovimentacoesInvalidas(): int
    {
        $tipos = array_map(fn (EstoqueMovimentacaoTipo $tipo) => $tipo->value, EstoqueMovimentacaoTipo::cases());
        $placeholders = implode(',', array_fill(0, count($tipos), '?'));

        return $this->count(
            "select count(*) as total
               from estoque_movimentacoes
              where id_variacao is null
                 or quantidade <= 0
                 or (id_deposito_origem is null and id_deposito_destino is null)
                 or tipo not in ({$placeholders})",
            $tipos
        );
    }

    private function countEstoqueDivergenteDoLivro(): int
    {
        return $this->count(
            "select count(*) as total
               from (
                    select coalesce(e.id_variacao, l.id_variacao) as id_variacao,
                           coalesce(e.id_deposito, l.id_deposito) as id_deposito,
                           coalesce(e.quantidade, 0) as saldo_atual,
                           coalesce(l.saldo_livro, 0) as saldo_livro
                      from estoque e
                 left join (
                           select id_variacao, id_deposito, sum(delta) as saldo_livro
                             from (
                                   select id_variacao, id_deposito_destino as id_deposito, quantidade as delta
                                     from estoque_movimentacoes
                                    where id_deposito_destino is not null
                                   union all
                                   select id_variacao, id_deposito_origem as id_deposito, -quantidade as delta
                                     from estoque_movimentacoes
                                    where id_deposito_origem is not null
                                  ) movimentos
                         group by id_variacao, id_deposito
                          ) l
                        on l.id_variacao = e.id_variacao
                       and l.id_deposito = e.id_deposito
                     union
                    select l.id_variacao,
                           l.id_deposito,
                           coalesce(e.quantidade, 0) as saldo_atual,
                           l.saldo_livro
                      from (
                           select id_variacao, id_deposito, sum(delta) as saldo_livro
                             from (
                                   select id_variacao, id_deposito_destino as id_deposito, quantidade as delta
                                     from estoque_movimentacoes
                                    where id_deposito_destino is not null
                                   union all
                                   select id_variacao, id_deposito_origem as id_deposito, -quantidade as delta
                                     from estoque_movimentacoes
                                    where id_deposito_origem is not null
                                  ) movimentos
                         group by id_variacao, id_deposito
                          ) l
                 left join estoque e
                        on e.id_variacao = l.id_variacao
                       and e.id_deposito = l.id_deposito
                    ) comparativo
              where saldo_atual <> saldo_livro"
        );
    }

    private function countTransferenciasDivergentes(): int
    {
        return $this->count(
            "select count(*) as total
               from estoque_transferencias t
          left join (
                    select ref_id,
                           count(*) as total_itens,
                           coalesce(sum(quantidade), 0) as total_pecas
                      from estoque_movimentacoes
                     where ref_type = 'transferencia'
                       and tipo = 'transferencia'
                  group by ref_id
                    ) m on m.ref_id = t.id
              where t.status = 'concluida'
                and (coalesce(m.total_itens, 0) <> t.total_itens
                  or coalesce(m.total_pecas, 0) <> t.total_pecas)"
        );
    }
}
