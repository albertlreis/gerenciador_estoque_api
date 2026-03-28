<?php

namespace Database\Seeders;

use App\Models\Estoque;
use App\Models\LocalizacaoDimensao;
use App\Models\LocalizacaoEstoque;
use App\Models\LocalizacaoValor;
use App\Support\InitialData\InventoryInitialDataService;
use Illuminate\Database\Seeder;
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
 * - Dados gerados são determinísticos por `estoque_id`, mantendo idempotência sem alterar registros a cada execução.
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
     * - Alterna de forma determinística entre criar como Área OU Localização física;
     * - Se criar como Área: setor/coluna/nivel e codigo_composto ficam NULL e as dimensões são limpas;
     * - Se criar como Localização física: área_id = NULL e os campos podem ser parciais, mas ao menos um é obrigatório.
     *
     * @param int $estoqueId
     * @param array<int> $areasIds
     * @param array<\App\Models\LocalizacaoDimensao> $dimensoes
     * @return void
     */
    protected function criarOuAtualizarLocalizacao(int $estoqueId, array $areasIds, array $dimensoes): void
    {
        // Decide se será Área ou Localização física de forma determinística.
        $usarArea = !empty($areasIds) && $estoqueId % 2 === 0;

        $setor  = null;
        $coluna = null;
        $nivel  = null;
        $areaId = null;

        if ($usarArea) {
            $areaId = $areasIds[$estoqueId % count($areasIds)];
        } else {
            $setor = (string) (($estoqueId % 10) + 1);
            $coluna = ['A', 'B', 'C', 'D', 'E', 'F'][$estoqueId % 6];
            $nivel = (string) (($estoqueId % 4) + 1);

            if ($estoqueId % 3 === 0) {
                $nivel = null;
            }

            if ($estoqueId % 5 === 0) {
                $coluna = null;
            }

            if ($setor === null && $coluna === null && $nivel === null) {
                $setor = '1';
            }
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
            $valor = $this->gerarValorExemploParaDimensao($dim->nome, (int) $localizacao->estoque_id);

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
     */
    protected function gerarValorExemploParaDimensao(string $nomeDimensao, int $semente): ?string
    {
        $nome = mb_strtolower($nomeDimensao);

        if (str_contains($nome, 'corredor')) {
            return (string) (($semente % 5) + 1);
        }
        if (str_contains($nome, 'prateleira')) {
            $letras = ['A', 'B', 'C', 'D'];
            return $letras[$semente % count($letras)];
        }
        if (str_contains($nome, 'rua') || str_contains($nome, 'modulo') || str_contains($nome, 'módulo')) {
            $prefix = str_contains($nome, 'rua') ? 'R' : 'M';
            return $prefix . (($semente % 9) + 1);
        }

        $letters = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $letter = $letters[$semente % count($letters)];
        $number = ($semente % 12) + 1;

        return $letter . $number;
    }

    /**
     * Garante a existência das áreas padrão e retorna seus IDs.
     *
     * @return array<int>
     */
    protected function seedAreasPadrao(): array
    {
        return app(InventoryInitialDataService::class)->seedAreasEstoque();
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
