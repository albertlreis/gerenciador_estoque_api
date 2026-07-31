-- Auditoria somente leitura: pedidos de venda entregues/finalizados com
-- registro central de entrega incompleto ou baixa de estoque insuficiente.
--
-- Compatibilidade: MySQL 8+.
-- Esta consulta não altera dados. Casos com vínculo legado ambíguo são
-- devolvidos no grupo AMBIGUA e não compõem as divergências confirmadas.

WITH
status_atual AS (
    SELECT psh.*
    FROM pedido_status_historico psh
    INNER JOIN (
        SELECT pedido_id, MAX(id) AS max_id
        FROM pedido_status_historico
        GROUP BY pedido_id
    ) ult ON ult.max_id = psh.id
),
linhas_variacao AS (
    SELECT
        id_pedido AS pedido_id,
        id_variacao,
        COUNT(*) AS total_linhas
    FROM pedido_itens
    GROUP BY id_pedido, id_variacao
),
entrega_item AS (
    SELECT
        pedido_item_id,
        COUNT(*) AS registros_entrega,
        SUM(quantidade_total) AS quantidade_total_central,
        SUM(quantidade_entregue) AS quantidade_entregue
    FROM produto_entrega_itens
    WHERE tipo_origem = 'pedido'
      AND status <> 'cancelado'
      AND pedido_item_id IS NOT NULL
    GROUP BY pedido_item_id
),
movimento_liquido AS (
    SELECT
        m.id,
        m.pedido_item_id,
        COALESCE(
            m.pedido_id,
            CASE WHEN m.ref_type = 'pedido' THEN m.ref_id END
        ) AS pedido_id,
        m.id_variacao,
        m.quantidade AS saida_bruta,
        COALESCE(SUM(est.quantidade), 0) AS quantidade_estornada,
        GREATEST(
            0,
            m.quantidade - COALESCE(SUM(est.quantidade), 0)
        ) AS saida_liquida
    FROM estoque_movimentacoes m
    LEFT JOIN estoque_movimentacoes est
      ON est.tipo = 'estorno'
     AND est.ref_type = 'estorno'
     AND est.ref_id = m.id
    WHERE m.tipo IN ('saida', 'saida_entrega_cliente')
      AND (
          m.pedido_item_id IS NOT NULL
          OR m.pedido_id IS NOT NULL
          OR (m.ref_type = 'pedido' AND m.ref_id IS NOT NULL)
      )
    GROUP BY
        m.id,
        m.pedido_item_id,
        m.pedido_id,
        m.ref_type,
        m.ref_id,
        m.id_variacao,
        m.quantidade
),
saida_direta AS (
    SELECT
        pedido_item_id,
        SUM(saida_bruta) AS saida_bruta,
        SUM(quantidade_estornada) AS quantidade_estornada,
        SUM(saida_liquida) AS saida_liquida
    FROM movimento_liquido
    WHERE pedido_item_id IS NOT NULL
    GROUP BY pedido_item_id
),
saida_legada AS (
    SELECT
        pedido_id,
        id_variacao,
        COUNT(*) AS movimentos_legados,
        SUM(saida_bruta) AS saida_bruta,
        SUM(quantidade_estornada) AS quantidade_estornada,
        SUM(saida_liquida) AS saida_liquida
    FROM movimento_liquido
    WHERE pedido_item_id IS NULL
      AND pedido_id IS NOT NULL
    GROUP BY pedido_id, id_variacao
),
saldo_atual AS (
    SELECT
        id_variacao,
        SUM(quantidade) AS estoque_fisico_atual
    FROM estoque
    GROUP BY id_variacao
),
reserva_atual AS (
    SELECT
        id_variacao,
        SUM(GREATEST(0, quantidade - quantidade_consumida)) AS reserva_ativa
    FROM estoque_reservas
    WHERE status = 'ativa'
      AND quantidade > quantidade_consumida
      AND (data_expira IS NULL OR data_expira > NOW())
    GROUP BY id_variacao
),
reconciliacao_documental AS (
    SELECT
        pri.pedido_item_id,
        SUM(pri.quantidade) AS quantidade_reconciliada,
        MAX(pri.aplicada_em) AS reconciliada_em
    FROM pedido_reconciliacao_itens pri
    INNER JOIN pedido_reconciliacoes pr
      ON pr.id = pri.pedido_reconciliacao_id
     AND pr.status = 'aplicada'
    WHERE pri.status = 'aplicada'
      AND pri.acao = 'DOCUMENTAR_SEM_BAIXA'
      AND pri.estornada_em IS NULL
    GROUP BY pri.pedido_item_id
),
auditoria AS (
    SELECT
        p.id AS pedido_id,
        p.numero_externo,
        c.nome AS cliente,
        sa.status AS status_atual,
        sa.data_status,
        pi.id AS pedido_item_id,
        pi.id_variacao,
        pv.referencia,
        pr.nome AS produto,
        pv.nome AS variacao,
        pi.quantidade AS quantidade_vendida,
        COALESCE(ei.registros_entrega, 0) AS registros_entrega,
        COALESCE(ei.quantidade_total_central, 0) AS quantidade_total_central,
        COALESCE(ei.quantidade_entregue, 0) AS quantidade_entregue,
        COALESCE(sd.saida_bruta, 0)
          + CASE
                WHEN lv.total_linhas = 1 THEN COALESCE(sl.saida_bruta, 0)
                ELSE 0
            END AS saida_bruta,
        COALESCE(sd.quantidade_estornada, 0)
          + CASE
                WHEN lv.total_linhas = 1 THEN COALESCE(sl.quantidade_estornada, 0)
                ELSE 0
            END AS quantidade_estornada,
        COALESCE(sd.saida_liquida, 0)
          + CASE
                WHEN lv.total_linhas = 1 THEN COALESCE(sl.saida_liquida, 0)
                ELSE 0
            END AS saida_liquida,
        COALESCE(saldo.estoque_fisico_atual, 0) AS estoque_fisico_atual,
        COALESCE(reserva.reserva_ativa, 0) AS reserva_ativa,
        COALESCE(rd.quantidade_reconciliada, 0) AS quantidade_reconciliada_documental,
        rd.reconciliada_em,
        CASE
            WHEN lv.total_linhas > 1
             AND COALESCE(sl.movimentos_legados, 0) > 0 THEN 1
            ELSE 0
        END AS vinculo_legado_ambiguo
    FROM pedidos p
    INNER JOIN status_atual sa
      ON sa.pedido_id = p.id
     AND sa.status IN ('entrega_cliente', 'finalizado')
    INNER JOIN pedido_itens pi ON pi.id_pedido = p.id
    INNER JOIN produto_variacoes pv ON pv.id = pi.id_variacao
    INNER JOIN produtos pr ON pr.id = pv.produto_id
    LEFT JOIN clientes c ON c.id = p.id_cliente
    INNER JOIN linhas_variacao lv
      ON lv.pedido_id = p.id
     AND lv.id_variacao = pi.id_variacao
    LEFT JOIN entrega_item ei ON ei.pedido_item_id = pi.id
    LEFT JOIN saida_direta sd ON sd.pedido_item_id = pi.id
    LEFT JOIN saida_legada sl
      ON sl.pedido_id = p.id
     AND sl.id_variacao = pi.id_variacao
    LEFT JOIN saldo_atual saldo ON saldo.id_variacao = pi.id_variacao
    LEFT JOIN reserva_atual reserva ON reserva.id_variacao = pi.id_variacao
    LEFT JOIN reconciliacao_documental rd ON rd.pedido_item_id = pi.id
    WHERE p.tipo = 'venda'
),
classificada AS (
    SELECT
        a.*,
        CASE
            WHEN a.registros_entrega = 0 THEN 'SEM_ITEM_ENTREGA'
            WHEN a.quantidade_entregue < a.quantidade_vendida THEN 'ENTREGA_INCOMPLETA'
            WHEN a.saida_liquida = 0 THEN 'BAIXA_AUSENTE'
            WHEN a.saida_liquida < a.quantidade_vendida THEN 'BAIXA_PARCIAL'
            ELSE NULL
        END AS classificacao_base
    FROM auditoria a
),
resultado AS (
    SELECT
        c.*,
        CASE
            WHEN c.vinculo_legado_ambiguo = 1 THEN 'AMBIGUA'
            WHEN c.quantidade_reconciliada_documental >= c.quantidade_vendida THEN 'RESOLVIDA'
            ELSE 'CONFIRMADA'
        END AS grupo_auditoria,
        CASE
            WHEN c.vinculo_legado_ambiguo = 1 THEN 'VINCULO_LEGADO_AMBIGUO'
            WHEN c.quantidade_reconciliada_documental >= c.quantidade_vendida THEN 'RECONCILIADA_DOCUMENTAL'
            ELSE c.classificacao_base
        END AS classificacao
    FROM classificada c
    WHERE c.vinculo_legado_ambiguo = 1
       OR c.classificacao_base IS NOT NULL
),
resumo AS (
    SELECT
        grupo_auditoria,
        classificacao,
        COUNT(*) AS total_itens_classificacao,
        COUNT(DISTINCT pedido_id) AS total_pedidos_classificacao
    FROM resultado
    GROUP BY grupo_auditoria, classificacao
)
SELECT
    r.grupo_auditoria,
    r.classificacao,
    s.total_itens_classificacao,
    s.total_pedidos_classificacao,
    r.pedido_id,
    r.numero_externo,
    r.cliente,
    r.status_atual,
    r.data_status,
    r.pedido_item_id,
    r.id_variacao,
    r.referencia,
    r.produto,
    r.variacao,
    r.quantidade_vendida,
    r.registros_entrega,
    r.quantidade_total_central,
    r.quantidade_entregue,
    r.saida_bruta,
    r.quantidade_estornada,
    r.saida_liquida,
    r.estoque_fisico_atual,
    r.reserva_ativa
    ,r.quantidade_reconciliada_documental
    ,r.reconciliada_em
FROM resultado r
INNER JOIN resumo s
  ON s.grupo_auditoria = r.grupo_auditoria
 AND s.classificacao = r.classificacao
ORDER BY
    CASE WHEN r.grupo_auditoria = 'CONFIRMADA' THEN 0 ELSE 1 END,
    r.classificacao,
    r.pedido_id,
    r.pedido_item_id;
