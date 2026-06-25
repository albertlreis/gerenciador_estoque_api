<?php

namespace App\Console\Commands\ContaAzul;

use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\Support\ContaAzulMoney;
use App\Models\ContaFinanceira;
use App\Models\LancamentoFinanceiro;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ContaAzulAuditarSaldosCommand extends Command
{
    protected $signature = 'conta-azul:auditar-saldos
        {--conta= : ID da conta financeira}
        {--apply : Aplica correcao explicita na conta informada}
        {--valor-correto= : Valor correto em reais}
        {--fator= : Fator para multiplicar o saldo atual salvo}';

    protected $description = 'Audita saldos financeiros importados da Conta Azul contra o livro financeiro local.';

    public function handle(): int
    {
        $contaId = $this->option('conta');
        $apply = (bool) $this->option('apply');
        $valorCorreto = $this->option('valor-correto');
        $fator = $this->option('fator');

        if ($apply) {
            if (!$contaId) {
                $this->error('Informe --conta para aplicar uma correcao.');
                return self::FAILURE;
            }

            if (($valorCorreto === null || $valorCorreto === '') && ($fator === null || $fator === '')) {
                $this->error('Informe --valor-correto ou --fator junto com --apply.');
                return self::FAILURE;
            }

            if ($valorCorreto !== null && $valorCorreto !== '' && $fator !== null && $fator !== '') {
                $this->error('Use apenas uma correcao por vez: --valor-correto ou --fator.');
                return self::FAILURE;
            }
        }

        $contas = ContaFinanceira::query()
            ->when($contaId, fn ($query) => $query->whereKey((int) $contaId))
            ->orderBy('nome')
            ->get();

        if ($contas->isEmpty()) {
            $this->warn('Nenhuma conta financeira encontrada.');
            return self::SUCCESS;
        }

        if ($apply) {
            return $this->applyCorrection($contas->first(), $valorCorreto, $fator);
        }

        $this->table(
            ['ID', 'Conta', 'Saldo salvo', 'Saldo Conta Azul', 'Saldo livro', 'Dif. salvo/livro', 'Dif. CA/livro', 'Atualizado em', 'Payload origem'],
            $contas->map(fn (ContaFinanceira $conta) => $this->auditRow($conta))->all()
        );

        return self::SUCCESS;
    }

    private function applyCorrection(ContaFinanceira $conta, mixed $valorCorreto, mixed $fator): int
    {
        $saldoAnterior = $conta->saldo_atual !== null ? (float) $conta->saldo_atual : null;
        $novoSaldo = null;
        $tipo = null;
        $valorReferencia = null;

        if ($valorCorreto !== null && $valorCorreto !== '') {
            $novoSaldo = ContaAzulMoney::parse($valorCorreto);
            $tipo = 'valor_correto';
            $valorReferencia = $valorCorreto;
        } elseif ($saldoAnterior !== null) {
            $factor = ContaAzulMoney::parse($fator);
            $novoSaldo = $factor !== null ? round($saldoAnterior * $factor, 2) : null;
            $tipo = 'fator';
            $valorReferencia = $fator;
        }

        if ($novoSaldo === null) {
            $this->error('Nao foi possivel interpretar o valor de correcao.');
            return self::FAILURE;
        }

        $meta = is_array($conta->meta_json) ? $conta->meta_json : [];
        $meta['saldo_auditoria_manual'] = [
            'aplicado_em' => now()->toDateTimeString(),
            'tipo' => $tipo,
            'valor_referencia' => $valorReferencia,
            'saldo_anterior' => $saldoAnterior,
            'saldo_novo' => $novoSaldo,
            'saldo_livro' => $this->saldoLivro($conta),
            'saldo_conta_azul_payload' => $this->saldoContaAzul($conta),
        ];

        $conta->forceFill([
            'saldo_atual' => $novoSaldo,
            'saldo_atual_em' => now(),
            'meta_json' => $meta,
        ])->save();

        $this->info(sprintf(
            'Saldo da conta #%d atualizado de %s para %s.',
            $conta->id,
            $this->money($saldoAnterior),
            $this->money($novoSaldo)
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function auditRow(ContaFinanceira $conta): array
    {
        $saldoSalvo = $conta->saldo_atual !== null ? (float) $conta->saldo_atual : null;
        $saldoContaAzul = $this->saldoContaAzul($conta);
        $saldoLivro = $this->saldoLivro($conta);

        return [
            (string) $conta->id,
            (string) $conta->nome,
            $this->money($saldoSalvo),
            $this->money($saldoContaAzul),
            $this->money($saldoLivro),
            $saldoSalvo === null ? '-' : $this->money($saldoSalvo - $saldoLivro),
            $saldoContaAzul === null ? '-' : $this->money($saldoContaAzul - $saldoLivro),
            $conta->saldo_atual_em ? Carbon::parse($conta->saldo_atual_em)->format('Y-m-d H:i:s') : '-',
            $this->payloadOrigem($conta),
        ];
    }

    private function saldoContaAzul(ContaFinanceira $conta): ?float
    {
        $meta = is_array($conta->meta_json) ? $conta->meta_json : [];
        $payload = $meta['conta_azul_saldo'] ?? null;

        if (!is_array($payload)) {
            return null;
        }

        return ContaAzulMoney::parseFromPayload($payload, ['saldo_atual', 'saldoAtual', 'saldo', 'valor', 'balance']);
    }

    private function payloadOrigem(ContaFinanceira $conta): string
    {
        $meta = is_array($conta->meta_json) ? $conta->meta_json : [];
        $payload = $meta['conta_azul_saldo'] ?? null;

        if (!is_array($payload)) {
            return '-';
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return '-';
        }

        return mb_strlen($json) > 120 ? mb_substr($json, 0, 117) . '...' : $json;
    }

    private function saldoLivro(ContaFinanceira $conta): float
    {
        $inicio = $conta->data_saldo_inicial
            ? Carbon::parse($conta->data_saldo_inicial)->startOfDay()
            : Carbon::parse('1900-01-01')->startOfDay();

        $movimentos = LancamentoFinanceiro::query()
            ->where('conta_id', $conta->id)
            ->where('status', LancamentoStatus::CONFIRMADO->value)
            ->where('data_movimento', '>=', $inicio)
            ->get()
            ->sum(fn (LancamentoFinanceiro $lancamento) => $this->signedValue($lancamento));

        return round((float) $conta->saldo_inicial + (float) $movimentos, 2);
    }

    private function signedValue(LancamentoFinanceiro $lancamento): float
    {
        $tipo = $lancamento->tipo?->value ?? (string) $lancamento->tipo;
        $valor = (float) $lancamento->valor;

        if ($tipo === LancamentoTipo::DESPESA->value) {
            return -$valor;
        }

        if ($tipo === LancamentoTipo::TRANSFERENCIA->value) {
            return str_contains(strtolower((string) $lancamento->descricao), 'recebida') ? $valor : -$valor;
        }

        return $valor;
    }

    private function money(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 2, ',', '.');
    }
}
