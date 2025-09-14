<?php

namespace App\Services;

use App\Models\LocalizacaoEstoque;
use App\Models\LocalizacaoValor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Serviço de Localização de Estoque.
 * - Persiste campos essenciais + dimensões dinâmicas.
 * - Gera e mantém o "codigo_composto" (ex.: 6-B1).
 */
class LocalizacaoEstoqueService
{
    public function listar(int $perPage = 20): LengthAwarePaginator
    {
        return LocalizacaoEstoque::with(['area', 'valores.dimensao'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function visualizar(int $id): Builder|array|Collection|Model
    {
        return LocalizacaoEstoque::with(['area', 'valores.dimensao'])->findOrFail($id);
    }

    public function criar(array $dados): LocalizacaoEstoque
    {
        return DB::transaction(function () use ($dados) {
            $loc = LocalizacaoEstoque::create([
                'estoque_id'   => $dados['estoque_id'],
                'setor'        => $dados['setor']        ?? null,
                'coluna'       => $dados['coluna']       ?? null,
                'nivel'        => $dados['nivel']        ?? null,
                'area_id'      => $dados['area_id']      ?? null,
                'observacoes'  => $dados['observacoes']  ?? null,
                'codigo_composto' => $this->montarCodigo($dados),
            ]);

            $this->sincronizarDimensoes($loc, $dados['dimensoes'] ?? []);
            return $loc;
        });
    }

    public function atualizar(int $id, array $dados): LocalizacaoEstoque
    {
        return DB::transaction(function () use ($id, $dados) {
            $loc = LocalizacaoEstoque::findOrFail($id);
            $loc->update([
                'estoque_id'   => $dados['estoque_id'],
                'setor'        => $dados['setor']        ?? null,
                'coluna'       => $dados['coluna']       ?? null,
                'nivel'        => $dados['nivel']        ?? null,
                'area_id'      => $dados['area_id']      ?? null,
                'observacoes'  => $dados['observacoes']  ?? null,
                'codigo_composto' => $this->montarCodigo($dados),
            ]);

            $this->sincronizarDimensoes($loc, $dados['dimensoes'] ?? []);
            return $loc;
        });
    }

    public function excluir(int $id): void
    {
        DB::transaction(function () use ($id) {
            $loc = LocalizacaoEstoque::findOrFail($id);
            $loc->valores()->delete();
            $loc->delete();
        });
    }

    /**
     * Monta o código composto no formato "Setor-ColunaNível".
     * Regras:
     * - Se houver área, não gera código (retorna null);
     * - Campos físicos são opcionais; se houver qualquer um, gera com placeholders:
     *    - esquerda = setor ou "-"
     *    - direita  = (coluna ou "-") + (nivel ou "")
     * Exemplos:
     *  setor=6, coluna=B, nivel=1   => "6-B1"
     *  setor=6, coluna=B, nivel=''  => "6-B"
     *  setor=null, coluna=B, nivel=1=> "-B1"
     *  setor=6, coluna=null, nivel=null => "6--"
     *  nenhum informado             => null
     */
    private function montarCodigo(array $d): ?string
    {
        if (!empty($d['area_id'])) {
            return null; // Exclusividade: com área não há código físico
        }

        $setor = $this->trimOrNull($d['setor'] ?? null);
        $col   = $this->trimOrNull($d['coluna'] ?? null);
        $niv   = $this->trimOrNull($d['nivel'] ?? null);

        if ($setor === null && $col === null && $niv === null) {
            return null;
        }

        $left  = $setor ?? '-';
        $right = ($col ?? '-') . ($niv ?? '');
        return "{$left}-{$right}";
    }

    /** Helper já existente nos seus seeders; inclua aqui se precisar */
    private function trimOrNull(?string $v): ?string
    {
        if ($v === null) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    /**
     * Sincroniza valores de dimensões dinâmicas.
     * @param LocalizacaoEstoque $loc
     * @param array<int,string|null> $dimensoes
     */
    private function sincronizarDimensoes(LocalizacaoEstoque $loc, array $dimensoes): void
    {
        $ids = array_keys($dimensoes);
        if (empty($ids)) {
            $loc->valores()->delete();
            return;
        }

        // Remove dimensões não informadas
        $loc->valores()->whereNotIn('dimensao_id', $ids)->delete();

        // Upsert simples por (localizacao_id, dimensao_id)
        foreach ($dimensoes as $dimId => $valor) {
            $valor = $valor !== null && trim($valor) === '' ? null : $valor;
            LocalizacaoValor::updateOrCreate(
                ['localizacao_id' => $loc->id, 'dimensao_id' => (int)$dimId],
                ['valor' => $valor]
            );
        }
    }
}
