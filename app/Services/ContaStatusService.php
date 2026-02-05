<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use BackedEnum;

class ContaStatusService
{
    public function syncPagar(ContaPagar $conta): void
    {
        if ($this->statusValue($conta->status) === ContaStatus::CANCELADA->value) return;

        $saldo = (float) $conta->saldo_aberto;
        $temPag = $conta->pagamentos()->exists();

        $novo = $saldo <= 0.00001
            ? ContaStatus::PAGA
            : ($temPag ? ContaStatus::PARCIAL : ContaStatus::ABERTA);

        if ($this->statusValue($conta->status) !== $novo->value) {
            $conta->status = $novo->value; // grava sempre string (DB)
            $conta->saveQuietly();
        }
    }

    public function syncReceber(ContaReceber $conta): void
    {
        if ($this->statusValue($conta->status) === ContaStatus::CANCELADA->value) return;

        $saldo = $this->toDecimalString($conta->saldo_aberto);
        $temPag = $conta->pagamentos()->exists();

        $novo = $this->isZeroOrNegative($saldo)
            ? ContaStatus::PAGA
            : ($temPag ? ContaStatus::PARCIAL : ContaStatus::ABERTA);

        if ($this->statusValue($conta->status) !== $novo->value) {
            $conta->status = $novo->value;
            $conta->saveQuietly();
        }
    }

    private function statusValue(mixed $status): string
    {
        if ($status instanceof BackedEnum) return $status->value;
        return (string) $status;
    }

    private function toDecimalString(mixed $value): string
    {
        if ($value === null || $value === '') return '0.00';
        $v = is_string($value) ? str_replace(',', '.', trim($value)) : (string)$value;
        if (function_exists('bcadd')) return bcadd($v, '0', 2);
        return number_format((float)$v, 2, '.', '');
    }

    private function isZeroOrNegative(string $value): bool
    {
        if (function_exists('bccomp')) {
            return bccomp($value, '0.00', 2) <= 0;
        }
        return (float)$value <= 0.00001;
    }
}
