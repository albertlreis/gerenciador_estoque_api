<?php

namespace App\Console\Commands;

use App\Services\PedidoEntregaReconciliacaoService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReconciliarPedidosEntreguesCommand extends Command
{
    protected $signature = 'pedidos:reconciliar-entregas
        {--exportar= : Gera um manifesto CSV com a fotografia atual}
        {--manifesto= : Valida ou aplica um manifesto CSV preenchido}
        {--dry-run : Força validação sem persistência}
        {--execute : Persiste o lote após todas as validações}
        {--rollback= : Estorna somente os registros gerados pelo lote informado}
        {--usuario= : ID do usuário responsável pela aplicação ou rollback}
        {--confirmar= : Confirmação literal do lote_id}';

    protected $description = 'Reconcilia entregas finalizadas sem baixa de estoque por manifesto físico auditável.';

    public function handle(PedidoEntregaReconciliacaoService $service): int
    {
        try {
            if ($arquivo = trim((string) $this->option('exportar'))) {
                if ($this->option('manifesto') || $this->option('rollback') || $this->option('execute')) {
                    return $this->falhar('A exportação não pode ser combinada com manifesto, execução ou rollback.');
                }
                $resultado = $service->exportar($arquivo);
                $this->info("Manifesto gerado em {$resultado['arquivo']}.");
                $this->table(['Lote', 'Linhas'], [[$resultado['lote_id'], $resultado['linhas']]]);
                $this->warn('Preencha saldo físico, confirmações, evidência e justificativa antes do dry-run.');

                return self::SUCCESS;
            }

            if ($loteRollback = trim((string) $this->option('rollback'))) {
                if (! $this->option('execute') || $this->option('dry-run')) {
                    return $this->falhar('Rollback exige --execute e não aceita --dry-run.');
                }
                $usuarioId = (int) $this->option('usuario');
                if ($usuarioId <= 0) {
                    return $this->falhar('Informe --usuario=<id> para o rollback.');
                }
                $resultado = $service->estornar($loteRollback, $usuarioId, (string) $this->option('confirmar'));
                $this->info("Rollback do lote {$resultado['lote_id']} concluído.");
                $this->line("Itens estornados: {$resultado['itens_estornados']}");

                return self::SUCCESS;
            }

            $arquivo = trim((string) $this->option('manifesto'));
            if ($arquivo === '') {
                return $this->falhar('Informe --exportar=<arquivo.csv>, --manifesto=<arquivo.csv> ou --rollback=<lote_id>.');
            }

            $linhas = $service->lerManifesto($arquivo);
            $usuarioId = $this->option('usuario') !== null ? (int) $this->option('usuario') : null;
            $analise = $service->analisar($linhas, $usuarioId);
            $this->mostrarAnalise($analise);

            if (! $analise['valido']) {
                return self::FAILURE;
            }

            if (! $this->option('execute') || $this->option('dry-run')) {
                $this->info('Dry-run concluído. Nenhum dado foi alterado.');

                return self::SUCCESS;
            }

            if (! $usuarioId) {
                return $this->falhar('Informe --usuario=<id> para aplicar o lote.');
            }

            $resultado = $service->aplicar($linhas, $usuarioId, (string) $this->option('confirmar'));
            $this->info($resultado['ja_aplicado']
                ? 'Lote já estava aplicado; nenhuma duplicidade foi criada.'
                : 'Lote aplicado integralmente.');

            return self::SUCCESS;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $mensagens) {
                foreach ($mensagens as $mensagem) {
                    $this->error((string) $mensagem);
                }
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string,mixed> $analise */
    private function mostrarAnalise(array $analise): void
    {
        $this->table(
            ['Lote', 'Linhas', 'Pedidos', 'Válido'],
            [[$analise['lote_id'] ?? '-', $analise['linhas'], $analise['pedidos'], $analise['valido'] ? 'SIM' : 'NÃO']]
        );
        $this->table(
            ['Ação', 'Linhas', 'Unidades'],
            collect($analise['acoes'])->map(fn ($totais, $acao) => [$acao, $totais['linhas'], $totais['unidades']])->values()->all()
        );
        foreach ($analise['erros'] as $erro) {
            $this->error($erro);
        }
    }

    private function falhar(string $mensagem): int
    {
        $this->error($mensagem);

        return self::FAILURE;
    }
}
