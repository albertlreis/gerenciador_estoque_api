<?php

namespace App\Services;

use App\Support\RefHelpers;

final class SierraNfeProductDescriptionParser
{
    private const CNPJ_RAIZ = '92726785';

    private const MARCADORES = <<<'REGEX'
~(?<![\pL\pN])(?:
    COR\s*(?:INOX|ALUM[IÍ]NIO|DO\s+FERRO)
    |COR\s*(?:(?:AC|AD|GV|MT)\s*\d+[A-Z]?(?=$|[\s-])|(?:PRI|PR|PRETO|BRANCO|COBRE)(?=$|[\s-]))
    |(?:PESP|P[ÉE]S)(?=\s*(?:MESMA\b|FORNECIDO\b|[A-Z]{1,4}\s*-?\s*\d))
    |(?:DET\.?\s*TECIDO|TIRAS\s+PVC|ENCOSTO|DEBRUM|BRA[ÇC]O|ESTRUTURA|TELA)(?=\s*(?:FORNECIDO\b|MESMA\b|[A-Z]{1,4}\s*-?\s*\d))
    |(?:TECIDO|TEC|COURO|ASS)(?=\s*(?:FORNECIDO\b|[A-Z]{1,4}\s*-?\s*\d))
)~iux
REGEX;

    /**
     * Confirma que a gramática específica pode ser aplicada à NF-e.
     */
    public function suportaEmitente(?string $cnpj, ?string $nome): bool
    {
        $cnpjNormalizado = preg_replace('/\D+/', '', (string) $cnpj) ?? '';
        if (str_starts_with($cnpjNormalizado, self::CNPJ_RAIZ)) {
            return true;
        }

        $nomeNormalizado = $this->normalizarParaComparacao((string) $nome);

        return preg_match('/^(?=.*\bSIERRA\b)(?=.*\bMOVEIS\b).+$/', $nomeNormalizado) === 1;
    }

    /**
     * @return array{
     *     identificado: bool,
     *     codigo: ?string,
     *     ref: ?string,
     *     codigo_origem: ?string,
     *     nome: string,
     *     descricao: string,
     *     atributos: array<string, string>,
     *     atributos_detectados: array<string, string>,
     *     atributos_lista: list<array{atributo: string, valor: string}>,
     *     atributos_detectados_lista: list<array{atributo: string, valor: string}>
     * }
     */
    public function interpretar(?string $codigoOriginal, string $descricaoOriginal): array
    {
        $descricaoIntegral = trim($descricaoOriginal);
        $descricaoAnalise = trim((string) preg_replace('/\s+/u', ' ', $descricaoIntegral));
        $marcadores = $this->encontrarMarcadores($descricaoAnalise);
        $atributosLista = [];
        $tecidosSecundarios = [];

        foreach ($marcadores as $indice => $marcador) {
            $fim = $marcadores[$indice + 1]['offset'] ?? strlen($descricaoAnalise);
            $segmento = trim(substr($descricaoAnalise, $marcador['offset'], $fim - $marcador['offset']));

            if ($this->isMarcadorMetal($segmento)) {
                $valor = $this->extrairMetal($segmento);
                if ($valor !== null) {
                    $this->adicionarAtributo($atributosLista, 'metal_vidro', $valor);
                }

                continue;
            }

            if ($this->isMarcadorMadeira($segmento)) {
                $valor = $this->extrairMadeira($segmento);
                if ($valor !== null) {
                    $this->adicionarAtributo($atributosLista, 'madeira', $valor);
                }

                continue;
            }

            if ($this->isMarcadorPes($segmento)) {
                $valor = $this->extrairPes($segmento);
                if ($valor !== null) {
                    $this->adicionarAtributo($atributosLista, 'pes', $valor);
                }

                continue;
            }

            if ($this->isMarcadorTecidoSecundario($segmento)) {
                $valor = $this->extrairTecidoSecundario($segmento);
                if ($valor !== null && ! in_array($valor, $tecidosSecundarios, true)) {
                    $tecidosSecundarios[] = $valor;
                }

                continue;
            }

            $valor = $this->extrairTecidoPrincipal($segmento);
            if ($valor !== null) {
                $this->adicionarAtributo($atributosLista, 'tecido_1', $valor);
            }
        }

        if ($tecidosSecundarios !== []) {
            $this->adicionarAtributo($atributosLista, 'tecido_2', implode(' · ', $tecidosSecundarios));
        }

        $atributos = $this->atributosListaParaMapa($atributosLista);
        $identificado = $atributos !== [];
        $codigoBase = RefHelpers::formatarReferencia($codigoOriginal) ?: $codigoOriginal;
        $nome = $identificado
            ? $this->extrairNomeBase($descricaoAnalise, $marcadores)
            : $descricaoIntegral;

        return [
            'identificado' => $identificado,
            'codigo' => $codigoBase,
            'ref' => $codigoBase,
            'codigo_origem' => $codigoOriginal,
            'nome' => $nome !== '' ? $nome : $descricaoIntegral,
            'descricao' => $descricaoIntegral,
            'atributos' => $atributos,
            'atributos_detectados' => $atributos,
            'atributos_lista' => $atributosLista,
            'atributos_detectados_lista' => $atributosLista,
        ];
    }

    /** @param list<array{atributo: string, valor: string}> $atributos */
    private function adicionarAtributo(array &$atributos, string $nome, string $valor): void
    {
        $atributos[] = [
            'atributo' => $nome,
            'valor' => $valor,
        ];
    }

    /**
     * O mapa permanece por compatibilidade e representa a ultima ocorrencia
     * quando o mesmo nome de atributo aparece mais de uma vez.
     *
     * @param  list<array{atributo: string, valor: string}>  $atributos
     * @return array<string, string>
     */
    private function atributosListaParaMapa(array $atributos): array
    {
        $mapa = [];

        foreach ($atributos as $atributo) {
            $mapa[$atributo['atributo']] = $atributo['valor'];
        }

        return $mapa;
    }

    /** @return array<int, array{offset: int}> */
    private function encontrarMarcadores(string $descricao): array
    {
        if ($descricao === '') {
            return [];
        }

        preg_match_all(self::MARCADORES, $descricao, $matches, PREG_OFFSET_CAPTURE);

        return array_map(
            static fn (array $match): array => ['offset' => (int) $match[1]],
            $matches[0] ?? []
        );
    }

    /** @param array<int, array{offset: int}> $marcadores */
    private function extrairNomeBase(string $descricao, array $marcadores): string
    {
        if ($marcadores === []) {
            return $descricao;
        }

        $nome = substr($descricao, 0, $marcadores[0]['offset']);

        return trim((string) preg_replace('/[\s\-–—,:;]+$/u', '', $nome));
    }

    private function isMarcadorMetal(string $segmento): bool
    {
        return preg_match('/^COR\s*(?:INOX|ALUM[IÍ]NIO|DO\s+FERRO)/iu', $segmento) === 1;
    }

    private function isMarcadorMadeira(string $segmento): bool
    {
        return preg_match(
            '/^COR\s*(?:(?:AC|AD|GV|MT)\s*\d+[A-Z]?(?=$|[\s-])|(?:PRI|PR|PRETO|BRANCO|COBRE)(?=$|[\s-]))/iu',
            $segmento
        ) === 1;
    }

    private function isMarcadorPes(string $segmento): bool
    {
        return preg_match('/^(?:PESP|P[ÉE]S)/iu', $segmento) === 1;
    }

    private function isMarcadorTecidoSecundario(string $segmento): bool
    {
        return preg_match(
            '/^(?:DET\.?\s*TECIDO|TIRAS\s+PVC|ENCOSTO|DEBRUM|BRA[ÇC]O|ESTRUTURA|TELA)/iu',
            $segmento
        ) === 1;
    }

    private function extrairMadeira(string $segmento): ?string
    {
        if (preg_match('/^COR\s*(AC|AD|GV|MT)\s*(\d+[A-Z]?)(.*)$/iu', $segmento, $matches) === 1) {
            $valor = strtoupper($matches[1].$matches[2]);
            $complemento = $this->extrairComplementoAposHifen($matches[3] ?? '');

            return $complemento !== null ? "{$valor} - {$complemento}" : $valor;
        }

        if (preg_match('/^COR\s*(PRI|PR|PRETO|BRANCO|COBRE)(.*)$/iu', $segmento, $matches) !== 1) {
            return null;
        }

        $valor = strtoupper($matches[1]);
        $complemento = $this->extrairComplementoAposHifen($matches[2] ?? '');

        return $complemento !== null ? "{$valor} - {$complemento}" : $valor;
    }

    private function extrairMetal(string $segmento): ?string
    {
        $tipos = [
            '/^COR\s*INOX/iu' => 'Inox',
            '/^COR\s*ALUM[IÍ]NIO/iu' => 'Alumínio',
            '/^COR\s*DO\s+FERRO/iu' => 'Ferro',
        ];

        foreach ($tipos as $padrao => $rotulo) {
            if (preg_match($padrao, $segmento, $matches) !== 1) {
                continue;
            }

            $valorBruto = trim(substr($segmento, strlen($matches[0])));
            $valor = $this->formatarTextoLivre($valorBruto);

            return $valor !== '' ? "{$rotulo}: {$valor}" : $rotulo;
        }

        return null;
    }

    private function extrairTecidoPrincipal(string $segmento): ?string
    {
        if (preg_match('/ALTEROU\s+PARA\s+([A-Z]{1,4}\s*-?\s*\d+[A-Z]?)/iu', $segmento, $matches) === 1) {
            return $this->normalizarCodigo($matches[1]);
        }

        if (preg_match('/^(?:TECIDO|TEC|COURO|ASS)\s*(FORNECIDO|[A-Z]{1,4}\s*-?\s*\d+[A-Z]?)/iu', $segmento, $matches) !== 1) {
            return null;
        }

        return $this->normalizarCodigo($matches[1]);
    }

    private function extrairTecidoSecundario(string $segmento): ?string
    {
        $rotulos = [
            '/^DET\.?\s*TECIDO/iu' => 'Det. Tecido',
            '/^TIRAS\s+PVC/iu' => 'Tiras PVC',
            '/^ENCOSTO/iu' => 'Encosto',
            '/^DEBRUM/iu' => 'Debrum',
            '/^BRA[ÇC]O/iu' => 'Braço',
            '/^ESTRUTURA/iu' => 'Estrutura',
            '/^TELA/iu' => 'Tela',
        ];

        foreach ($rotulos as $padrao => $rotulo) {
            if (preg_match($padrao, $segmento, $matches) !== 1) {
                continue;
            }

            $restante = trim(substr($segmento, strlen($matches[0])));
            $valor = $this->extrairValorOrientado($restante);

            return $valor !== null ? "{$rotulo}: {$valor}" : null;
        }

        return null;
    }

    private function extrairPes(string $segmento): ?string
    {
        $restante = trim((string) preg_replace('/^(?:PESP|P[ÉE]S)/iu', '', $segmento, 1));
        if (preg_match('/^MESMA\s+COR\s+DO\s+TECIDO\b/iu', $restante) === 1) {
            return 'Mesma cor do tecido';
        }

        return $this->extrairValorOrientado($restante);
    }

    private function extrairValorOrientado(string $valor): ?string
    {
        if (preg_match('/ALTEROU\s+PARA\s+([A-Z]{1,4}\s*-?\s*\d+[A-Z]?)/iu', $valor, $matches) === 1) {
            return $this->normalizarCodigo($matches[1]);
        }

        if (preg_match('/^(FORNECIDO|[A-Z]{1,4}\s*-?\s*\d+[A-Z]?)/iu', $valor, $matches) === 1) {
            return $this->normalizarCodigo($matches[1]);
        }

        if (preg_match('/^MESMA\s+COR\s+DO\s+TECIDO\b/iu', $valor) === 1) {
            return 'Mesma cor do tecido';
        }

        return null;
    }

    private function normalizarCodigo(string $valor): string
    {
        $valor = strtoupper(trim((string) preg_replace('/\s+/u', ' ', $valor)));

        return (string) preg_replace('/\s*-\s*/', '-', $valor);
    }

    private function extrairComplementoAposHifen(string $valor): ?string
    {
        if (preg_match('/^\s*-\s*(.+)$/u', $valor, $matches) !== 1) {
            return null;
        }

        $complemento = strtoupper(trim((string) preg_replace('/\s+/u', ' ', $matches[1])));

        return $complemento !== '' ? $complemento : null;
    }

    private function formatarTextoLivre(string $valor): string
    {
        $valor = trim((string) preg_replace('/\s*-\s*/u', ' ', $valor));
        $tokens = preg_split('/\s+/u', $valor) ?: [];

        return implode(' ', array_map(static function (string $token): string {
            if (preg_match('/\d/u', $token) === 1 || mb_strlen($token, 'UTF-8') <= 2) {
                return mb_strtoupper($token, 'UTF-8');
            }

            return mb_convert_case(mb_strtolower($token, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }, $tokens));
    }

    private function normalizarParaComparacao(string $valor): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if ($ascii === false) {
            $ascii = $valor;
        }

        return strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $ascii)));
    }
}
