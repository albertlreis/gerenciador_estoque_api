<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarChamadoRequest extends FormRequest
{
    /**
     * Determina se o usuário está autorizado a executar esta ação.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Ajuste para sua política/autorização
        return true;
    }

    /**
     * Regras de validação para atualização do chamado.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origem_tipo'     => 'nullable|string|in:pedido,consignacao,estoque',
            'origem_id'       => 'nullable|integer',
            'cliente_id'      => 'nullable|integer|exists:clientes,id',
            'fornecedor_id'   => 'nullable|integer|exists:fornecedores,id',
            'assistencia_id'  => 'nullable|integer|exists:assistencias,id',
            'prioridade'      => 'nullable|string|in:baixa,media,alta,critica',
            'canal_abertura'  => 'nullable|string|in:loja,site,telefone,whatsapp',
            'observacoes'     => 'nullable|string',
        ];
    }

    /**
     * Mapeia os atributos para mensagens mais amigáveis (opcional).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'origem_tipo'    => 'origem',
            'origem_id'      => 'ID da origem',
            'cliente_id'     => 'cliente',
            'fornecedor_id'  => 'fornecedor',
            'assistencia_id' => 'assistência',
            'prioridade'     => 'prioridade',
            'canal_abertura' => 'canal de abertura',
            'observacoes'    => 'observações',
        ];
    }
}
