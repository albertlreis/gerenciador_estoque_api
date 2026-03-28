<?php

namespace App\Enums;

/**
 * Estratégia de vínculo produto/variação na importação de pedidos por PDF (preview + confirmação).
 */
enum EstrategiaVinculoImportacao: string
{
    case REF_SELECAO = 'REF_SELECAO';
    case PDF_NOVO = 'PDF_NOVO';
    case HIBRIDO = 'HIBRIDO';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function normalizar(?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $u = strtoupper(trim($valor));
        foreach (self::cases() as $case) {
            if ($case->value === $u) {
                return $case->value;
            }
        }

        return null;
    }
}
