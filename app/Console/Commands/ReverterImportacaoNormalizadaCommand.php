<?php

namespace App\Console\Commands;

use App\Models\ImportacaoNormalizada;
use App\Services\Import\ImportacaoNormalizadaRollbackService;
use Illuminate\Console\Command;
use Throwable;

class ReverterImportacaoNormalizadaCommand extends Command
{
    protected $signature = 'importacoes:reverter-normalizada
        {importacao_id : ID da importacao normalizada a ser revertida}
        {--dry-run : Apenas analisa as movimentacoes e o saldo disponivel para estorno}';

    protected $description = 'Reverte auditavelmente uma importacao normalizada por meio de estornos das movimentacoes de estoque.';

    public function handle(ImportacaoNormalizadaRollbackService $service): int
    {
        /** @var ImportacaoNormalizada $importacao */
        $importacao = ImportacaoNormalizada::query()->findOrFail((int) $this->argument('importacao_id'));

        if ((bool) $this->option('dry-run')) {
            $resumo = $service->dryRun($importacao);
            $this->exibirResumo($resumo);

            if (!($resumo['apta_para_reversao'] ?? false)) {
                $this->error('Dry-run identificou saldo insuficiente para estorno em uma ou mais chaves.');

                return self::FAILURE;
            }

            $this->info('Dry-run concluído sem bloqueios para reversão.');

            return self::SUCCESS;
        }

        try {
            $resultado = $service->reverter($importacao);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info((string) ($resultado['mensagem'] ?? 'Importação revertida.'));
        $this->exibirResumo((array) ($resultado['resumo'] ?? []));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $resumo
     */
    private function exibirResumo(array $resumo): void
    {
        $this->line(sprintf('Importação: #%d', (int) ($resumo['importacao_id'] ?? 0)));
        $this->line('Status atual: ' . (string) ($resumo['status_importacao'] ?? 'desconhecido'));
        $this->line('Movimentações alvo: ' . (int) ($resumo['total_movimentacoes'] ?? 0));
        $this->line('Quantidade total envolvida: ' . (int) ($resumo['total_quantidade'] ?? 0));
        $this->line('Já estornadas: ' . (int) ($resumo['total_movimentacoes_ja_estornadas'] ?? 0));
        $this->line('A estornar agora: ' . (int) ($resumo['total_movimentacoes_a_estornar'] ?? 0));
        $this->line('Chaves afetadas: ' . (int) ($resumo['total_chaves_afetadas'] ?? 0));
        $this->line('Chaves com saldo insuficiente: ' . (int) ($resumo['total_chaves_com_saldo_insuficiente'] ?? 0));

        $saldoInsuficiente = collect($resumo['saldo_insuficiente'] ?? []);
        if ($saldoInsuficiente->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['id_variacao', 'id_deposito', 'quantidade_atual', 'quantidade_a_estornar', 'deficit'],
                $saldoInsuficiente->all()
            );
        }
    }
}
