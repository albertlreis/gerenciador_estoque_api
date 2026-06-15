<?php

namespace App\Http\Resources;

use App\Helpers\AuthHelper;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsignacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        $hoje = now();
        $itens = $this->todas_consignacoes ?? collect();

        $statusPedido = 'pendente';
        $temPendente = $itens->contains('status', 'pendente');
        $temComprado = $itens->contains('status', 'comprado');
        $temDevolvido = $itens->contains('status', 'devolvido');
        $temParcial = $itens->contains('status', 'parcial');
        $podeGerenciar = AuthHelper::hasPermissao('consignacoes.gerenciar');
        $isAdmin = AuthHelper::hasPerfil('Administrador');
        $temHistoricoComercial = $itens->contains(fn ($item) => $item->quantidadeComprada() > 0 || $item->quantidadeDevolvida() > 0);
        $podeDesfazer = $podeGerenciar && (!$temHistoricoComercial || $isAdmin);

        if ($temPendente) {
            if ($itens->where('status', 'pendente')->pluck('prazo_resposta')->contains(fn($prazo) => $prazo && $hoje->gt($prazo))) {
                $statusPedido = 'vencido';
            }
        } elseif ($temParcial || ($temComprado && $temDevolvido)) {
            $statusPedido = 'parcial';
        } elseif ($temComprado) {
            $statusPedido = 'comprado';
        } elseif ($temDevolvido) {
            $statusPedido = 'devolvido';
        }

        return [
            'id' => $this->id,
            'pedido_id' => $this->pedido_id,
            'numero_externo' => optional($this->pedido)->numero_externo,
            'cliente_nome' => optional($this->pedido->cliente)->nome,
            'vendedor_nome' => optional($this->pedido->usuario)->nome,
            'parceiro_nome' => optional($this->pedido->parceiro)->nome,
            'data_envio' => optional($this->data_envio)->format('d/m/Y'),
            'prazo_resposta' => optional($this->prazo_resposta)->format('d/m/Y'),
            'status' => $this->status,
            'status_calculado' => $statusPedido,
            'pode_desfazer' => $podeDesfazer,
            'tem_historico_comercial' => $temHistoricoComercial,
            'desfazer_bloqueio' => !$podeGerenciar
                ? 'Sem permissao para gerenciar consignacoes.'
                : ($temHistoricoComercial && !$isAdmin ? 'Apenas administradores podem desfazer consignacoes com venda ou devolucao registrada.' : null),
        ];
    }
}
