# Relatório de validação – importação batch (PDF e XML)

**Data:** 2026-03-04  
**Comandos executados (dentro do container `fabio-gerenciador-estoque-api`):**

```bash
php artisan sierra:test-import-pedidos-pdf --dir="/var/www/storage/leitor_pdf_examples" --commit=0 --confirm=1 --timeout=60
php artisan sierra:test-import-pedidos-xml --dir="/var/www/storage/leitor_pdf_examples" --commit=0
```

---

## PDFs (`leitor_pdf_examples`)

| Arquivo | Status | Motivo / Etapa |
|--------|--------|-----------------|
| 039823 - QUAKER.pdf | **FAIL** | Nenhum item identificado no PDF (extrator retornou 0 itens). Erro amigável 422: "Nenhum item foi identificado no PDF. Arquivo: 039823 - QUAKER.pdf". |
| 16552 - SIERRA.pdf | **OK** | Extração e confirmação (rollback) concluídas. |
| 16839 - SIERRA.pdf | **OK** | Extração e confirmação (rollback) concluídas. |
| 17829 - SIERRA.pdf | **OK** | Extração e confirmação (rollback) concluídas. |
| 18002 - SIERRA.pdf | **FAIL** | Nenhum item identificado no PDF (extrator retornou 0 itens). Erro amigável 422. |
| 18335 - SIERRA.pdf | **OK** | Extração e confirmação (rollback) concluídas. |
| 19105 - SIERRA.pdf | **OK** | Extração e confirmação (rollback) concluídas. |
| 19166 - SIERRA.pdf | **FAIL** | Nenhum item identificado (fallback pypdf extraiu texto, mas parser não casou itens). Erro amigável 422 (não mais 500 Invalid octal). |
| 60958 - AVANTI.pdf | **FAIL** | Nenhum item identificado no PDF. Erro amigável 422. |

**Resumo PDF:** OK = 5, FAIL = 4. Falhas com mensagem clara 422 (sem 500 genérico).

---

## XMLs (NFe – ADORNOS_XML_NFE)

| Arquivo | Status | Motivo / Etapa |
|--------|--------|-----------------|
| 35250207266606000112550020000450551000623840-nfe.xml | **OK** | Parser NFe em Laravel (sem extrator PDF). 14 itens, número 45055. |
| 35260201368233000104550030000450951891771400-nfe.xml | **OK** | Parser NFe em Laravel. 44 itens, número 45095. |

**Resumo XML:** OK = 2, FAIL = 0.

---

## Commits (sem push)

**Repositório `gerenciador_estoque_api`:**
1. `fix(importacao): corrigir docker URL extrator + batches`
2. `feat(comunicacao): integração envio status pedido e cobrança`
3. `feat(importacao): parser NFe XML em Laravel para ADORNOS_XML_NFE (sem extrator PDF)`
4. `fix(importacao): 422 amigável para PDF sem itens e repasse 422 do extrator Python`

**Repositório `leitor_pdf_sierra`:**
1. `fix: fallback pypdf para Invalid octal e debug texto quando itens=0`

---

## Validação final após ETAPA 2 (leitor)

**Execução:** `php artisan sierra:test-import-pedidos-pdf --dir="/var/www/storage/leitor_pdf_examples" --commit=0 --confirm=1 --timeout=60`  
**Consolidado:** `/var/www/storage/logs/import-pdf-tests/20260304_153555/consolidado.json`

| Arquivo | Status | Itens | Observação |
|--------|--------|-------|------------|
| 039823 - QUAKER.pdf | **WARN** | 0 | Sem itens; fluxo manual habilitado. |
| 16552 - SIERRA.pdf | **OK** | 1 | Confirmação executada com rollback. |
| 16839 - SIERRA.pdf | **OK** | 36 | Confirmação executada com rollback. |
| 17829 - SIERRA.pdf | **OK** | 18 | Confirmação executada com rollback. |
| 18002 - SIERRA.pdf | **WARN** | 0 | Sem itens (scan/texto vazio), não confirma. |
| 18335 - SIERRA.pdf | **OK** | 33 | Confirmação executada com rollback. |
| 19105 - SIERRA.pdf | **OK** | 12 | Confirmação executada com rollback. |
| 19166 - SIERRA.pdf | **OK** | 1 | Confirmação passou após ajuste de mapeamento do payload. |
| 60958 - AVANTI.pdf | **OK** | 5 | Confirmação executada com rollback. |

**Resumo final:** OK = 7, WARN = 2, FAIL = 0.  
O `19166` deixou de falhar na confirmação; o `18002` permanece em WARN com inserção manual, como esperado para PDF sem itens extraíveis.

