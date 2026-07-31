<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroEstoqueDTO;
use App\Helpers\AuthHelper;
use App\Http\Requests\FiltroEstoqueRequest;
use App\Http\Resources\MovimentacaoResource;
use App\Http\Resources\ProdutoEstoqueResource;
use App\Http\Resources\ResumoEstoqueResource;
use App\Models\Estoque;
use App\Services\EstoqueAjusteService;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class EstoqueController extends Controller
{
    /**
     * Lista o estoque atual agrupado por produto e depósito com filtros e ordenação.
     *
     * @queryParam periodo array Opcional. [YYYY-MM-DD, YYYY-MM-DD] para considerar movimentações no intervalo.
     *
     * @param  \App\Http\Requests\FiltroEstoqueRequest  $request  Instância da requisição HTTP com os parâmetros de filtro
     * @param  EstoqueService  $service  Serviço responsável pela lógica de estoque
     */
    public function listarEstoqueAtual(FiltroEstoqueRequest $request, EstoqueService $service): JsonResponse|Response|BinaryFileResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $dto = new FiltroEstoqueDTO($request->validated());

        if ($request->get('export') === 'pdf') {
            return $service->exportarPdf($dto);
        }

        if ($request->get('export') === 'excel') {
            return $service->exportarExcel($dto);
        }

        $result = $service->listar($dto);

        return ProdutoEstoqueResource::collection($result)->response();
    }

    /**
     * Retorna um resumo com total de produtos, peças e depósitos.
     *
     * @param  Request  $request
     */
    public function resumoEstoque(FiltroEstoqueRequest $request, EstoqueService $service): JsonResponse
    {
        $dto = new FiltroEstoqueDTO($request->validated());
        $resumo = $service->gerarResumo($dto);

        return response()->json(new ResumoEstoqueResource($resumo));
    }

    /**
     * Lista os depósitos com estoque positivo de uma variação específica.
     */
    public function porVariacao(int $id_variacao): JsonResponse
    {
        $estoques = Estoque::with('deposito')
            ->where('id_variacao', $id_variacao)
            ->where('quantidade', '>', 0)
            ->get()
            ->filter(fn ($e) => $e->deposito)
            ->map(fn ($e) => [
                'id' => $e->deposito->id,
                'nome' => $e->deposito->nome,
                'quantidade' => $e->quantidade,
            ])
            ->values();

        return response()->json($estoques);
    }

    /**
     * Registra um ajuste manual auditavel a partir do saldo final desejado.
     */
    public function registrarAjusteManual(Request $request, EstoqueAjusteService $ajustes): JsonResponse
    {
        if (! AuthHelper::podeRegistrarAjusteManualEstoque()) {
            return response()->json([
                'message' => 'Sem permissao para registrar ajuste manual de estoque.',
            ], 403);
        }

        $dados = $request->validate([
            'estoque_id' => ['nullable', 'integer', 'exists:estoque,id', 'required_without_all:variacao_id,deposito_id'],
            'variacao_id' => ['nullable', 'integer', 'exists:produto_variacoes,id', 'required_without:estoque_id'],
            'deposito_id' => ['nullable', 'integer', 'exists:depositos,id', 'required_without:estoque_id'],
            'quantidade_final' => ['required', 'integer', 'min:0'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if (! empty($dados['estoque_id'])) {
                $estoque = Estoque::query()->findOrFail((int) $dados['estoque_id']);
                $dados['variacao_id'] = (int) $estoque->id_variacao;
                $dados['deposito_id'] = (int) $estoque->id_deposito;
            }

            $resultado = $ajustes->ajustarSaldoFinal(
                (int) $dados['variacao_id'],
                (int) $dados['deposito_id'],
                (int) $dados['quantidade_final'],
                auth()->id(),
                $dados['observacao'] ?? null
            );
            $movimentacao = $resultado['movimentacao'];

            return response()->json([
                'data' => new MovimentacaoResource($movimentacao),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
