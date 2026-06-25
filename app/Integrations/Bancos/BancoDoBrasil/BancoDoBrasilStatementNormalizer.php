<?php

namespace App\Integrations\Bancos\BancoDoBrasil;

use App\Models\ContaFinanceira;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BancoDoBrasilStatementNormalizer
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function normalize(array $payload, ContaFinanceira $conta, CarbonInterface $start, CarbonInterface $end): array
    {
        $transactions = [];

        foreach ($this->transactionRows($payload) as $row) {
            $date = $this->parseDate($this->firstScalar($row, [
                'dataLancamento',
                'dataMovimento',
                'data',
                'dtLancamento',
                'dataBalancete',
                'postedAt',
            ]));
            $amount = $this->parseMoney($this->firstScalar($row, [
                'valorLancamento',
                'valor',
                'amount',
                'valorTransacao',
                'valorMovimento',
            ]));

            if ($date === null || $amount === null) {
                continue;
            }

            $description = $this->description($row);
            $signedAmount = $this->applySign($amount, $row, $description);
            $originId = $this->providerId($row);
            $seed = implode('|', [
                'bb',
                (string) $conta->agencia,
                (string) $conta->conta,
                (string) $conta->conta_dv,
                $date,
                number_format($signedAmount, 2, '.', ''),
                $originId,
                $description,
            ]);
            $hash = hash('sha256', $originId !== null ? 'bb|' . $originId : $seed);
            $identifier = $originId !== null ? 'bb:' . $originId : 'bb:hash:' . $hash;

            $transactions[] = [
                'fit_id' => $originId,
                'identificador' => $identifier,
                'hash_unico' => $hash,
                'data_movimento' => $date,
                'valor' => $signedAmount,
                'tipo_ofx' => $signedAmount < 0 ? 'DEBIT' : 'CREDIT',
                'checknum' => $this->documentNumber($row),
                'memo' => $description,
                'origem' => 'bb_api',
                'origem_transacao_id' => $originId,
                'raw_json' => $row,
            ];
        }

        return [
            'banco_codigo' => $this->digits($conta->banco_codigo) ?: '001',
            'banco_nome' => $conta->banco_nome ?: 'Banco do Brasil',
            'agencia' => $conta->agencia,
            'conta' => $conta->conta,
            'conta_dv' => $conta->conta_dv,
            'moeda' => $this->firstScalar($payload, ['moeda', 'currency', 'currencyCode']) ?: ($conta->moeda ?: 'BRL'),
            'data_inicio' => $start->toDateString(),
            'data_fim' => $end->toDateString(),
            'saldo_final' => $this->saldoFinal($payload),
            'saldo_final_em' => $this->saldoDate($payload, $end),
            'transacoes' => $transactions,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function transactionRows(array $payload): array
    {
        foreach ([
            'lancamentos',
            'transacoes',
            'movimentacoes',
            'items',
            'itens',
            'data',
            'extrato',
        ] as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value) && $this->isListOfRows($value)) {
                return $value;
            }
        }

        foreach ($payload as $value) {
            if (is_array($value) && $this->isListOfRows($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $value
     */
    private function isListOfRows(array $value): bool
    {
        if ($value === [] || !array_is_list($value)) {
            return false;
        }

        return is_array($value[0] ?? null);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function firstScalar(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function description(array $row): string
    {
        $parts = [];
        foreach ([
            'textoDescricaoHistorico',
            'descricaoHistorico',
            'descricao',
            'historico',
            'complemento',
            'textoInformacaoComplementar',
            'documento',
            'numeroDocumento',
        ] as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        $description = trim(implode(' - ', array_values(array_unique($parts))));

        return $description !== '' ? Str::limit($description, 500, '') : 'Lancamento Banco do Brasil';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function providerId(array $row): ?string
    {
        return $this->firstScalar($row, [
            'id',
            'identificador',
            'codigoIdentificador',
            'codigoAutenticacao',
            'numeroSequencialLancamento',
            'numeroDocumento',
            'documento',
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function documentNumber(array $row): ?string
    {
        return $this->firstScalar($row, [
            'numeroDocumento',
            'documento',
            'codigoAutenticacao',
            'numeroSequencialLancamento',
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function applySign(float $amount, array $row, string $description): float
    {
        if ($amount < 0) {
            return round($amount, 2);
        }

        $signal = Str::lower(Str::ascii(implode(' ', array_filter([
            $this->firstScalar($row, ['indicadorSinalLancamento']),
            $this->firstScalar($row, ['indicadorTipoLancamento']),
            $this->firstScalar($row, ['tipoLancamento']),
            $this->firstScalar($row, ['tipo']),
            $this->firstScalar($row, ['natureza']),
            $description,
        ]))));

        $isDebit = preg_match('/\b(d|debito|debit|saida|pagamento|tarifa)\b/', $signal) === 1;
        $isCredit = preg_match('/\b(c|credito|credit|entrada|recebimento)\b/', $signal) === 1;

        if ($isDebit && !$isCredit) {
            return round(abs($amount) * -1, 2);
        }

        return round(abs($amount), 2);
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 8) {
            if (preg_match('/^\d{4}/', $digits) && (int) substr($digits, 4, 2) <= 12) {
                return Carbon::createFromFormat('Ymd', $digits)->toDateString();
            }

            return Carbon::createFromFormat('dmY', $digits)->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseMoney(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^\d,.\-]+/', '', $value) ?? '';
        if ($value === '') {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($lastDot !== false && $lastComma !== false) {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function saldoFinal(array $payload): ?float
    {
        $direct = $this->parseMoney($this->firstScalar($payload, [
            'saldoFinal',
            'saldoAtual',
            'saldo',
            'balance',
            'currentBalance',
        ]));
        if ($direct !== null) {
            return $direct;
        }

        foreach (['saldos', 'saldoConta', 'accountBalance'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value)) {
                $nested = $this->parseMoney($this->firstScalar($value, [
                    'saldoFinal',
                    'saldoAtual',
                    'saldo',
                    'valor',
                    'amount',
                ]));
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function saldoDate(array $payload, CarbonInterface $end): string
    {
        $date = $this->parseDate($this->firstScalar($payload, [
            'dataSaldo',
            'dataSaldoFinal',
            'dataAtualizacao',
            'updatedAt',
        ]));

        return ($date ? Carbon::parse($date) : $end)->startOfDay()->toDateTimeString();
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }
}
