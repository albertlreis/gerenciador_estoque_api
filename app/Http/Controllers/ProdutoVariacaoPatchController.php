<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\ProdutoVariacaoPatchRequest;
use App\Models\ProdutoVariacao;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProdutoVariacaoPatchController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function update(ProdutoVariacaoPatchRequest $request, int $id): JsonResponse
    {
        if ($forbidden = $this->autorizarEdicao()) {
            return $forbidden;
        }

        $variacao = ProdutoVariacao::query()->findOrFail($id);
        $dados = $request->validated();
        $audit = Arr::get($dados, 'audit', []);

        $updates = Arr::only($dados, ['referencia', 'nome', 'preco', 'custo', 'codigo_barras']);
        $antes = $variacao->getAttributes();

        $variacao->fill($updates);
        $dirty = $variacao->getDirty();

        if (empty($dirty)) {
            return response()->json(['data' => $variacao->load('atributos', 'imagem')]);
        }

        DB::transaction(function () use ($variacao, $dirty, $antes, $audit) {
            $variacao->save();

            if (array_key_exists('preco', $dirty)) {
                $this->sincronizarPrecoEmCarrinhosRascunho((int) $variacao->id, (float) $variacao->preco);
            }

            $label = trim((string) ($audit['label'] ?? '')) ?: 'Atualização de variação';
            $motivo = trim((string) ($audit['motivo'] ?? '')) ?: null;
            $origin = trim((string) ($audit['origin'] ?? '')) ?: null;
            $metadataExtra = is_array($audit['metadata'] ?? null) ? $audit['metadata'] : [];

            $this->auditLogger->logUpdate(
                $variacao,
                'produto_variacoes',
                $label,
                [
                    '__before' => $antes,
                    '__dirty' => $dirty,
                    'motivo' => $motivo,
                    'origin' => $origin,
                    'metadata' => $metadataExtra,
                    'carrinho_id' => $metadataExtra['carrinho_id'] ?? null,
                ]
            );
        });

        return response()->json(['data' => $variacao->fresh()->load('atributos', 'imagem')]);
    }

    private function sincronizarPrecoEmCarrinhosRascunho(int $variacaoId, float $novoPreco): void
    {
        $precoFormatado = number_format($novoPreco, 2, '.', '');
        $agora = now();

        DB::table('carrinho_itens as ci')
            ->join('carrinhos as c', 'c.id', '=', 'ci.id_carrinho')
            ->where('c.status', 'rascunho')
            ->where('ci.id_variacao', $variacaoId)
            ->whereNull('ci.outlet_id')
            ->update([
                'ci.preco_unitario' => $precoFormatado,
                'ci.subtotal' => DB::raw("ci.quantidade * {$precoFormatado}"),
                'ci.updated_at' => $agora,
            ]);
    }

    private function autorizarEdicao(): ?JsonResponse
    {
        $permissoes = [
            'produto_variacoes.editar',
            'produtos.editar',
            'produtos.gerenciar',
        ];

        foreach ($permissoes as $permissao) {
            if (AuthHelper::hasPermissao($permissao)) {
                return null;
            }
        }

        return response()->json(['message' => 'Sem permissão para editar variações.'], 403);
    }
}

