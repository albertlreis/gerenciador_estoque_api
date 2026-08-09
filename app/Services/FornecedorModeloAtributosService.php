<?php

namespace App\Services;

use Illuminate\Support\Str;

class FornecedorModeloAtributosService
{
    private const ORDEM_FALLBACK = ['madeira', 'metal_vidro', 'tecido_1', 'tecido_2'];

    /**
     * @return list<array{atributo:string,valor:string}>
     */
    public function classificar(?string $modelo): array
    {
        $partes = collect(explode('#', (string) $modelo))
            ->map(fn (string $parte) => (string) Str::of($parte)->squish())
            ->filter(fn (string $parte) => $parte !== '')
            ->values();

        $resultado = [];
        $tiposOcupados = [];
        $tecidosSubstantivos = 0;

        foreach ($partes as $parte) {
            [$tipo, $valor, $marcadorTecido] = $this->classificarParte($parte);

            if ($tipo === 'tecido') {
                $tipo = $tecidosSubstantivos === 0 ? 'tecido_1' : 'tecido_2';
                $tecidosSubstantivos++;
            } elseif ($marcadorTecido) {
                $tipo = 'tecido_2';
            } elseif ($tipo === null) {
                $tipo = collect(self::ORDEM_FALLBACK)
                    ->first(fn (string $candidato) => ! isset($tiposOcupados[$candidato]))
                    ?? 'tecido_2';
            }

            $resultado[] = ['atributo' => $tipo, 'valor' => $valor];
            $tiposOcupados[$tipo] = true;
        }

        return $resultado;
    }

    /**
     * @param list<array{atributo:string,valor:string}> $atributos
     * @return array<string,string>
     */
    public function comoMapa(array $atributos): array
    {
        $mapa = [];
        foreach ($atributos as $atributo) {
            $mapa[$atributo['atributo']] = $atributo['valor'];
        }

        return $mapa;
    }

    /**
     * @return array{0:?string,1:string,2:bool}
     */
    private function classificarParte(string $parte): array
    {
        $normalizado = $this->normalizar($parte);

        if (preg_match('/^(?:TECIDO\s+UNICO|MESMA\s+COR\s+DO\s+TECIDO)$/', $normalizado) === 1) {
            return [null, $parte, true];
        }

        if (preg_match('/^COR\s*:\s*(.+)$/iu', $parte, $match) === 1) {
            $valor = (string) Str::of($match[1])->squish();
            return [$this->contemMetalVidro($this->normalizar($valor)) ? 'metal_vidro' : 'madeira', $valor, false];
        }

        if ($this->contemMetalVidro($normalizado)) {
            return ['metal_vidro', $parte, false];
        }

        if (preg_match('/\b(?:PEDRA|MARMORE|CORDA|PALHA|RADICA|TAMPO|TRAVERTINO|CALACATA|MATARAZZO|AMETISTA)\b/', $normalizado) === 1
            || preg_match('/^(?:CAR|CPA)\s*\d/', $normalizado) === 1
            || preg_match('/\b(?:VERDE\s+(?:ESMERALDA|SAPPHIRE)|VIA\s+LACTEA|KALAHARY)\b/', $normalizado) === 1) {
            return ['tecido_2', $parte, false];
        }

        if (preg_match('/^(?:TEC|TECIDO|COURO)\s*:\s*(.+)$/iu', $parte, $match) === 1) {
            return ['tecido', (string) Str::of($match[1])->squish(), false];
        }

        if (preg_match('/\b(?:TECIDO|COURO|ASS|ASSENTO|ENC|ENCOSTO|DEB|FRENTE|COSTAS)\b/', $normalizado) === 1
            || preg_match('/(?:^|\s)[A-Z]{1,2}-\d{4,}(?:\s|$)/', $normalizado) === 1) {
            return ['tecido', $parte, false];
        }

        if (preg_match('/^(?:AC|AD|MT|PRI|BRI|GV|PBD|NF|RC|POE)(?:\s|-|\d|$)/', $normalizado) === 1) {
            return ['madeira', $parte, false];
        }

        return [null, $parte, false];
    }

    private function contemMetalVidro(string $normalizado): bool
    {
        return preg_match('/\b(?:INOX|ALUMINIO|FERRO|METAL|CROMADO|VIDRO)\b|(?:GOLD|ONIX)/', $normalizado) === 1;
    }

    private function normalizar(string $valor): string
    {
        return (string) Str::of($valor)->squish()->upper()->ascii();
    }
}
