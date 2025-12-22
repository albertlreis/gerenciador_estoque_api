<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ClienteService;
use App\Http\Requests\StoreClienteRequest;
use Illuminate\Validation\ValidationException;

class ClienteController extends Controller
{
    protected ClienteService $service;

    public function __construct(ClienteService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /clientes?nome=&documento=...
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'nome' => $request->string('nome')->toString(),
            'documento' => $request->string('documento')->toString(),
        ];

        return response()->json(
            $this->service->listarClientes($filtros)
        );
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreClienteRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (!empty($data['documento']) && $this->service->documentoDuplicado($data['documento'])) {
            throw ValidationException::withMessages(['documento' => 'Documento já cadastrado.']);
        }

        if (!empty($data['documento']) && !$this->service->validarDocumento($data['documento'], $data['tipo'])) {
            throw ValidationException::withMessages(['documento' => 'Documento inválido.']);
        }

        $cliente = $this->service->criarClienteComEnderecos($data);

        return response()->json($cliente, 201);
    }

    /**
     * @throws ValidationException
     */
    public function update(StoreClienteRequest $request, Cliente $cliente): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['documento']) && $data['documento'] !== $cliente->documento) {
            if ($this->service->documentoDuplicado($data['documento'], $cliente->id)) {
                throw ValidationException::withMessages(['documento' => 'Documento já cadastrado.']);
            }

            $tipo = $data['tipo'] ?? $cliente->tipo;
            if (!$this->service->validarDocumento($data['documento'], $tipo)) {
                throw ValidationException::withMessages(['documento' => 'Documento inválido.']);
            }
        }

        $cliente = $this->service->atualizarClienteComEnderecos($cliente, $data);

        return response()->json($cliente);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        return response()->json($cliente->load(['enderecos']));
    }

    public function destroy(Cliente $cliente): JsonResponse
    {
        $cliente->delete();
        return response()->json(null, 204);
    }

    /**
     * GET /clientes/verifica-documento/{documento}/{id?}
     */
    public function verificaDocumento(string $documento, ?int $id = null): JsonResponse
    {
        return response()->json([
            'existe' => $this->service->documentoDuplicado($documento, $id),
        ]);
    }
}
