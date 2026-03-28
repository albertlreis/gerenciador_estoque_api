<?php

namespace App\Enums;

enum ImportacaoNormalizadaAcaoLinha: string
{
    case CRIAR_PRODUTO_E_VARIACAO = 'criar_produto_e_variacao';
    case CRIAR_VARIACAO_EM_PRODUTO_EXISTENTE = 'criar_variacao_em_produto_existente';
    case ATUALIZAR_VARIACAO_EXISTENTE = 'atualizar_variacao_existente';
    case CADASTRO_APENAS_SEM_ESTOQUE = 'cadastro_apenas_sem_estoque';
    case PENDENTE_REVISAO_MANUAL = 'pendente_revisao_manual';
    case BLOQUEADA_POR_CONFLITO = 'bloqueada_por_conflito';
    case IGNORADA_POR_ERRO_ESTRUTURAL = 'ignorada_por_erro_estrutural';
    case IGNORADA_MANUALMENTE = 'ignorada_manualmente';

    public function label(): string
    {
        return match ($this) {
            self::CRIAR_PRODUTO_E_VARIACAO => 'Criar produto e variação',
            self::CRIAR_VARIACAO_EM_PRODUTO_EXISTENTE => 'Criar variação em produto existente',
            self::ATUALIZAR_VARIACAO_EXISTENTE => 'Atualizar variação existente',
            self::CADASTRO_APENAS_SEM_ESTOQUE => 'Cadastro sem estoque',
            self::PENDENTE_REVISAO_MANUAL => 'Pendente de revisão manual',
            self::BLOQUEADA_POR_CONFLITO => 'Bloqueada por conflito',
            self::IGNORADA_POR_ERRO_ESTRUTURAL => 'Ignorada por erro estrutural',
            self::IGNORADA_MANUALMENTE => 'Ignorada manualmente',
        };
    }
}
