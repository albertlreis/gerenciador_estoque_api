<?php

namespace App\Http\Controllers;

use App\Exports\FinanceiroExtratoExport;
use App\Services\FinanceiroExtratoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinanceiroExtratoController extends Controller
{
    public function __construct(private readonly FinanceiroExtratoService $service) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->montar($this->validated($request)),
        ]);
    }

    public function exportPdf(Request $request): Response
    {
        $dados = $this->service->montar($this->validated($request));

        $pdf = Pdf::loadView('pdf.financeiro-extrato', [
            ...$dados,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->filename($dados, 'pdf'));
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $dados = $this->service->montar($this->validated($request));

        return Excel::download(new FinanceiroExtratoExport($dados), $this->filename($dados, 'xlsx'));
    }

    public function resumo(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->resumo($this->validatedResumo($request)),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'conta_id' => ['required', 'integer', 'exists:contas_financeiras,id'],
        ]);
    }

    private function validatedResumo(Request $request): array
    {
        return $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'conta_id' => ['required_without:conta_ids', 'integer', 'exists:contas_financeiras,id'],
            'conta_ids' => ['required_without:conta_id', 'array'],
            'conta_ids.*' => ['integer', 'exists:contas_financeiras,id'],
        ]);
    }

    private function filename(array $dados, string $ext): string
    {
        $conta = $dados['conta'];
        $slug = $conta->slug ?: str($conta->nome)->slug('-')->toString();
        $inicio = str_replace('/', '-', $dados['periodo']['inicio']);
        $fim = str_replace('/', '-', $dados['periodo']['fim']);

        return "extrato-{$slug}-{$inicio}-{$fim}.{$ext}";
    }
}
