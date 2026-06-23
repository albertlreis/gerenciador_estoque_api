<?php

namespace App\Services\ConciliacaoBancaria;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OfxParser
{
    /**
     * @return array{
     *   banco_codigo:?string,banco_nome:?string,agencia:?string,conta:?string,conta_dv:?string,
     *   moeda:string,data_inicio:?string,data_fim:?string,saldo_final:?float,saldo_final_em:?string,
     *   transacoes:array<int,array<string,mixed>>
     * }
     */
    public function parse(string $raw): array
    {
        $content = $this->normalizeEncoding($raw);

        if (!str_contains(strtoupper($content), '<OFX>')) {
            throw ValidationException::withMessages([
                'arquivo' => 'Arquivo OFX invalido ou sem bloco OFX.',
            ]);
        }

        $acct = $this->splitAccount($this->tagValue($content, 'ACCTID'));
        $bankId = $this->tagValue($content, 'BANKID') ?: $this->tagValue($content, 'FID');

        $parsed = [
            'banco_codigo' => $bankId,
            'banco_nome' => $this->tagValue($content, 'ORG') ?: $this->tagValue($content, 'MKTGINFO'),
            'agencia' => $this->tagValue($content, 'BRANCHID'),
            'conta' => $acct['conta'],
            'conta_dv' => $acct['dv'],
            'moeda' => $this->tagValue($content, 'CURDEF') ?: 'BRL',
            'data_inicio' => $this->parseDate($this->tagValue($content, 'DTSTART')),
            'data_fim' => $this->parseDate($this->tagValue($content, 'DTEND')),
            'saldo_final' => $this->parseMoney($this->tagValue($content, 'BALAMT')),
            'saldo_final_em' => $this->parseDateTime($this->tagValue($content, 'DTASOF')),
            'transacoes' => [],
        ];

        foreach ($this->transactionBlocks($content) as $block) {
            $amount = $this->parseMoney($this->tagValue($block, 'TRNAMT'));
            $date = $this->parseDate($this->tagValue($block, 'DTPOSTED'));
            if ($amount === null || $date === null) {
                continue;
            }

            $fitId = $this->tagValue($block, 'FITID');
            $checkNum = $this->tagValue($block, 'CHECKNUM');
            $memo = $this->tagValue($block, 'MEMO');
            $type = $this->tagValue($block, 'TRNTYPE');
            $uniqueSeed = implode('|', [
                $parsed['banco_codigo'] ?? '',
                $parsed['conta'] ?? '',
                $parsed['conta_dv'] ?? '',
                $date,
                number_format($amount, 2, '.', ''),
                $checkNum ?? '',
                $memo ?? '',
            ]);
            $hash = hash('sha256', $fitId ?: $uniqueSeed);

            $parsed['transacoes'][] = [
                'fit_id' => $fitId,
                'identificador' => $fitId ?: 'hash:' . $hash,
                'hash_unico' => $hash,
                'data_movimento' => $date,
                'valor' => $amount,
                'tipo_ofx' => $type,
                'checknum' => $checkNum,
                'memo' => $memo,
            ];
        }

        if (!$parsed['transacoes']) {
            throw ValidationException::withMessages([
                'arquivo' => 'Nenhuma transacao bancaria encontrada no OFX.',
            ]);
        }

        return $parsed;
    }

    private function normalizeEncoding(string $raw): string
    {
        $header = strtoupper(substr($raw, 0, 600));
        $declaresCp1252 = str_contains($header, 'CHARSET:1252') || str_contains($header, 'ENCODING:USASCII');

        if (!$declaresCp1252 && function_exists('mb_check_encoding') && mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $converted = iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
        return $converted === false ? $raw : $converted;
    }

    /**
     * @return array<int,string>
     */
    private function transactionBlocks(string $content): array
    {
        $parts = preg_split('/<STMTTRN>/i', $content) ?: [];
        array_shift($parts);

        return array_values(array_filter(array_map(function (string $part): string {
            $blockParts = preg_split('/<\/STMTTRN>|<\/BANKTRANLIST>/i', $part) ?: [];
            return trim($blockParts[0] ?? '');
        }, $parts)));
    }

    private function tagValue(string $content, string $tag): ?string
    {
        if (!preg_match('/<' . preg_quote($tag, '/') . '>\s*([^<\r\n]*)/i', $content, $match)) {
            return null;
        }

        $value = trim($match[1]);
        return $value !== '' ? $value : null;
    }

    /**
     * @return array{conta:?string,dv:?string}
     */
    private function splitAccount(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return ['conta' => null, 'dv' => null];
        }

        if (preg_match('/^(.+)-([A-Za-z0-9])$/', $value, $match)) {
            return [
                'conta' => trim($match[1]),
                'dv' => trim($match[2]),
            ];
        }

        return ['conta' => $value, 'dv' => null];
    }

    private function parseDate(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) < 8) {
            return null;
        }

        return Carbon::createFromFormat('Ymd', substr($digits, 0, 8))->toDateString();
    }

    private function parseDateTime(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) < 8) {
            return null;
        }

        if (strlen($digits) >= 14) {
            return Carbon::createFromFormat('YmdHis', substr($digits, 0, 14))->toDateTimeString();
        }

        return Carbon::createFromFormat('Ymd', substr($digits, 0, 8))->startOfDay()->toDateTimeString();
    }

    private function parseMoney(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $normalized = str_contains($value, ',')
            ? str_replace(['.', ','], ['', '.'], $value)
            : $value;

        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }
}
