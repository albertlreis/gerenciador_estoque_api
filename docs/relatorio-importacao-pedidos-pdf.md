# Relatório — Importação de pedidos via PDF

## Fluxo ponta-a-ponta
1) Front envia o PDF:
- `gerenciador_estoque_front/src/components/ImportacaoPedidoPDF.jsx`
- `PedidosApi.importarArquivo()` chama `POST /api/v1/pedidos/import` com `multipart/form-data`.

2) Back extrai e cria preview/staging:
- `gerenciador_estoque_api/app/Http/Controllers/PedidoController.php` -> `importar()`
- `ExtratorPedidoPythonService::processar()` envia o PDF para a API Python e recebe JSON.
- `ImportacaoPedidoService::mesclarItensComVariacoes()` enriquece itens com dados do banco.
- Salva staging em `pedido_importacoes` (`PedidoImportacao::updateOrCreate`) com `dados_json`.
- Resposta inclui `importacao_id` + `dados` para preview.

3) Front confirma a importação:
- `gerenciador_estoque_front/src/components/ImportacaoPedidoPDF.jsx`
- `PedidosApi.confirmarImportacaoPdf()` chama `POST /api/v1/pedidos/import/pdf/confirm` com payload JSON.

4) Back valida e persiste:
- `gerenciador_estoque_api/app/Services/ImportacaoPedidoService.php` -> `confirmarImportacaoPDF()`
- Valida payload, normaliza datas, abre transação, cria `Pedido` + `PedidoItem` + `PedidoStatusHistorico`.
- Atualiza `pedido_importacoes` para `confirmado`.

## Arquivos/classes/funções envolvidos
Back-end (gerenciador_estoque_api)
- `gerenciador_estoque_api/routes/api.php`
- `gerenciador_estoque_api/app/Http/Controllers/PedidoController.php`
- `gerenciador_estoque_api/app/Services/ImportacaoPedidoService.php`
- `gerenciador_estoque_api/app/Services/ExtratorPedidoPythonService.php`
- `gerenciador_estoque_api/app/Models/PedidoImportacao.php`
- `gerenciador_estoque_api/app/Support/Dates/DateNormalizer.php`
- `gerenciador_estoque_api/tests/Unit/DateNormalizerTest.php`
- `gerenciador_estoque_api/tests/Feature/PedidoImportacaoPdfDatasTest.php`

Front-end (gerenciador_estoque_front)
- `gerenciador_estoque_front/src/components/ImportacaoPedidoPDF.jsx`
- `gerenciador_estoque_front/src/api/pedidosApi.js`
- `gerenciador_estoque_front/src/constants/endpointsEstoque.js`
- `gerenciador_estoque_front/src/utils/date.js`

## Payloads (com foco em datas)
### POST `/api/v1/pedidos/import`
Request:
- `multipart/form-data`
- campo `arquivo` (PDF)

Response (preview):
- `importacao_id: number`
- `dados.pedido` (parcial):
  - `numero_externo: string|null`
  - `data_pedido: string|null`
  - `data_inclusao: string|null`
  - `data_entrega: string|null`
  - `total: number`
- `dados.itens: array`

### POST `/api/v1/pedidos/import/pdf/confirm`
Request (JSON):
- `importacao_id: number|null`
- `cliente: object` (opcional se `pedido.tipo = reposicao`)
- `pedido`:
  - `tipo: "venda"|"reposicao"`
  - `numero_externo: string|null`
  - `data_pedido: string|null`
  - `data_inclusao: string|null`
  - `data_entrega: string|null`
  - `total: number|null`
- `itens: array`
  - `nome: string`
  - `quantidade: number`
  - `valor: number`
  - `id_categoria: number`
  - `id_deposito: number|null`

Response:
- `message: string`
- `id: number` (pedido criado)
- `tipo: string`
- `itens: array` (resumo)

## Diagnóstico do bug de data
- O front envia datas como strings vindas do PDF (ex.: `"14.08.20"`, `"14/08/2020"`).
- O back validava com `date` (PHP) e normalizava com `Carbon::parse()`.
- Alguns formatos não eram aceitos pelo validator ou eram interpretados de forma ambígua, gerando:
  - `422` em validação, ou
  - normalização retornando `null` e fallback para `now()` na criação do pedido.

Exemplo:
- `data_pedido = "14.08.20"` falhava para o validator e o pedido era criado com data atual.

## Formato canônico e estratégia retrocompatível
Formato canônico:
- DATE: `YYYY-MM-DD`
- DATETIME: `YYYY-MM-DD HH:mm:ss`

Estratégia:
- **Back-end** aceita e normaliza múltiplos formatos via `DateNormalizer`:
  - `DD/MM/YYYY`
  - `DD/MM/YY`
  - `DD.MM.YY`
  - `DD.MM.YYYY`
  - `YYYY-MM-DD`
  - ISO 8601 (com/sem timezone)
- **Front-end** envia datas no formato canônico `YYYY-MM-DD`.
- Campos e nomes no payload mantidos (retrocompatível).

## Testes adicionados
Back-end:
- `tests/Unit/DateNormalizerTest.php`
  - cobre múltiplos formatos e erro em formato inválido.
- `tests/Feature/PedidoImportacaoPdfDatasTest.php`
  - confirma importação com datas em `DD/MM/YYYY` e `DD.MM.YY`.
- `database/migrations/2025_04_08_000000_create_acesso_usuarios_table_for_tests.php`
  - cria `acesso_usuarios` **somente em ambiente testing** para permitir as FKs de `pedidos`.

Como rodar:
- `php artisan test`

## Observações importantes
- O fluxo atual não persiste `data_inclusao` e `data_entrega` no modelo `Pedido` (não estavam em `fillable`).
- Logs adicionados em importação/confirm (sem conteúdo sensível do PDF): início, preview reutilizado, extração concluída, erro e confirmação.
