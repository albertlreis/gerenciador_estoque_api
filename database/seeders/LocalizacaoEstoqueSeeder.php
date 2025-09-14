<?php

namespace Database\Seeders;

use App\Models\AreaEstoque;
use App\Models\Estoque;
use App\Models\LocalizacaoDimensao;
use App\Models\LocalizacaoEstoque;
use App\Models\LocalizacaoValor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de Localizações de Estoque (regras atuais).
 *
 * O que este seeder faz:
 * 1) Garante a existência das áreas padrão (Assistência, Devolução, Tampos Avariados, Tampos Clientes, Avarias);
 * 2) (Opcional) Carrega dimensões ativas em `localizacao_dimensoes` — se não houver, nada é criado;
 * 3) Para cada item em `estoque`, cria/atualiza uma linha em `localizacoes_estoque` (1:1 por estoque_id),
 *    obedecendo às regras:
 *      - Exclusividade: OU área_id OU localização física (setor/coluna/nivel parcial);
 *      - Localização física pode ser parcial (qualquer combinação); se não houver área, garante ao menos um campo;
 *      - codigo_composto só quando NÃO houver área (usa placeholders).
 * 4) Sincroniza `localizacao_valores` (dimensões). Se a linha estiver em Área, remove valores.
 *
 * Observações:
 * - Idempotente (pode rodar repetidas vezes).
 * - Dados gerados são pseudo-aleatórios para teste; ajuste se quiser refletir casos reais.
 */
class LocalizacaoEstoqueSeeder extends Seeder
{
    /**
     * Executa o seeder.
     */
    public function run(): void
    {
        // 1) Garante áreas padrão
        $areasIds = $this->seedAreasPadrao();

        // 2) Lê dimensões ativas (se não houver, apenas não criamos valores)
        $dimensoes = LocalizacaoDimensao::query()
            ->where('ativo', true)
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get(['id', 'nome'])
            ->all();

        // 3) Processa itens de estoque em lotes
        Estoque::query()
            ->orderBy('id')
            ->chunkById(1000, function ($itens) use ($areasIds, $dimensoes) {
                DB::transaction(function () use ($itens, $areasIds, $dimensoes) {
                    foreach ($itens as $estoque) {
                        $this->criarOuAtualizarLocalizacao((int) $estoque->id, $areasIds, $dimensoes);
                    }
                });
            });
    }

    /**
     * Gera ou atualiza uma localização para um determinado estoque_id.
     *
     * Regras:
     * - 50/50 (aprox.) entre criar como Área OU Localização física;
     * - Se criar como Área: setor/coluna/nivel e codigo_composto ficam NULL e as dimensões são limpas;
     * - Se criar como Localização física: área_id = NULL e os campos podem ser parciais, mas ao menos um é obrigatório.
     *
     * @param int $estoqueId
     * @param array<int> $areasIds
     * @param array<\App\Models\LocalizacaoDimensao> $dimensoes
     * @return void
     * @throws \Random\RandomException
     */
    protected function criarOuAtualizarLocalizacao(int $estoqueId, array $areasIds, array $dimensoes): void
    {
        // Decide se será Área ou Localização física
        $usarArea = (random_int(1, 100) <= 50) && !empty($areasIds);

        $setor  = null;
        $coluna = null;
        $nivel  = null;
        $areaId = null;

        if ($usarArea) {
            // Escolhe uma área aleatória e zera os físicos
            $areaId = Arr::random($areasIds);
        } else {
            // Gera localização física PARCIAL (cada campo pode ou não vir)
            // Garante que pelo menos um campo seja preenchido
            do {
                $setor  = (random_int(1, 100) <= 70) ? (string) random_int(1, 10) : null; // 70% chance
                $coluna = (random_int(1, 100) <= 60) ? Arr::random(['A','B','C','D','E','F']) : null; // 60%
                $nivel  = (random_int(1, 100) <= 55) ? (string) random_int(1, 4) : null; // 55%
            } while ($setor === null && $coluna === null && $nivel === null);
        }

        $codigo = $usarArea
            ? null
            : $this->montarCodigoComposto($setor, $coluna, $nivel);

        /** @var LocalizacaoEstoque $localizacao */
        $localizacao = LocalizacaoEstoque::query()->updateOrCreate(
            ['estoque_id' => $estoqueId], // Unique key
            [
                'setor'           => $setor,
                'coluna'          => $coluna,
                'nivel'           => $nivel,
                'area_id'         => $areaId,
                'codigo_composto' => $codigo,
                'observacoes'     => null,
            ]
        );

        // Sincroniza dimensões:
        // - Se for Área, limpa todas as dimensões;
        // - Se for Localização física, sincroniza conforme dimensões ativas.
        $this->sincronizarDimensoes($localizacao, $dimensoes, $habilitar = !$usarArea);
    }

    /**
     * Monta o código composto "Setor-ColunaNível".
     * - Esquerda: setor ou "-"
     * - Direita: (coluna ou "-") + (nivel ou "")
     * - Se nenhum campo informado, retorna null
     *
     * Exemplos:
     *  setor=6, coluna=B, nivel=1   => "6-B1"
     *  setor=6, coluna=B, nivel=''  => "6-B"
     *  setor=null, coluna=B, nivel=1=> "-B1"
     *  setor=6, coluna=null, nivel=null => "6--"
     *
     * @param string|null $setor
     * @param string|null $coluna
     * @param string|null $nivel
     * @return string|null
     */
    protected function montarCodigoComposto(?string $setor, ?string $coluna, ?string $nivel): ?string
    {
        $s = $this->trimOrNull($setor);
        $c = $this->trimOrNull($coluna);
        $n = $this->trimOrNull($nivel);

        if ($s === null && $c === null && $n === null) {
            return null;
        }

        $left  = $s ?? '-';
        $right = ($c ?? '-') . ($n ?? '');
        return "{$left}-{$right}";
    }

    /**
     * Sincroniza valores de dimensões para uma localização (idempotente).
     * - Quando $habilitar=false, apaga todos os valores existentes.
     * - Quando $habilitar=true:
     *    * Remove valores cujas dimensões não estão mais ativas;
     *    * Cria/atualiza um valor "exemplo" para cada dimensão ativa.
     *
     * @param \App\Models\LocalizacaoEstoque $localizacao
     * @param array<\App\Models\LocalizacaoDimensao> $dimensoes
     * @param bool $habilitar
     * @return void
     * @throws \Random\RandomException
     */
    protected function sincronizarDimensoes(LocalizacaoEstoque $localizacao, array $dimensoes, bool $habilitar = true): void
    {
        if (!$habilitar) {
            $localizacao->valores()->delete();
            return;
        }

        $dimIds = array_map(fn ($d) => (int) $d->id, $dimensoes);

        if (empty($dimIds)) {
            // Não há dimensões ativas -> limpa tudo
            $localizacao->valores()->delete();
            return;
        }

        // Remove quaisquer registros de dimensões que não estejam na lista ativa
        $localizacao->valores()
            ->whereNotIn('dimensao_id', $dimIds)
            ->delete();

        // Para cada dimensão ativa, grava/atualiza um valor "exemplo" coerente
        foreach ($dimensoes as $dim) {
            $valor = $this->gerarValorExemploParaDimensao($dim->nome);

            LocalizacaoValor::query()->updateOrCreate(
                [
                    'localizacao_id' => $localizacao->id,
                    'dimensao_id'    => (int) $dim->id,
                ],
                ['valor' => $valor]
            );
        }
    }

    /**
     * Gera um valor de exemplo para a dimensão informada.
     * Ajuste conforme seus padrões reais (se necessário).
     *
     * @param string $nomeDimensao
     * @return string|null
     * @throws \Random\RandomException
     */
    protected function gerarValorExemploParaDimensao(string $nomeDimensao): ?string
    {
        $nome = mb_strtolower($nomeDimensao);

        if (str_contains($nome, 'corredor')) {
            return (string) random_int(1, 5); // "1".."5"
        }
        if (str_contains($nome, 'prateleira')) {
            $letras = ['A', 'B', 'C', 'D'];
            return Arr::random($letras);
        }
        if (str_contains($nome, 'rua') || str_contains($nome, 'modulo') || str_contains($nome, 'módulo')) {
            $prefix = str_contains($nome, 'rua') ? 'R' : 'M';
            return $prefix . random_int(1, 9);
        }

        // Fallback genérico curto (3~4 chars), ex.: "X3", "B12"
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $l = $letters[random_int(0, 25)];
        $n = random_int(1, 12);
        return $l . $n;
    }

    /**
     * Garante a existência das áreas padrão e retorna seus IDs.
     *
     * @return array<int>
     */
    protected function seedAreasPadrao(): array
    {
        $nomes = [
            'Assistência',
            'Devolução',
            'Tampos Avariados',
            'Tampos Clientes',
            'Avarias',
        ];

        $ids = [];
        foreach ($nomes as $nome) {
            $area = AreaEstoque::query()->firstOrCreate(['nome' => $nome], ['descricao' => null]);
            $ids[] = (int) $area->id;
        }

        return $ids;
    }

    /**
     * Normaliza string: trim e retorna null se ficar vazia.
     *
     * @param string|null $v
     * @return string|null
     */
    protected function trimOrNull(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}
