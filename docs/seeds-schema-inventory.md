# Inventário de Schema x Seeds (API Estoque)

## Objetivo

Mapear as tabelas críticas do schema atual (migrations) e o status de cobertura de seeds para execução idempotente.

## Observações de schema relevantes

- `acesso_usuarios` é pré-requisito de várias FKs (`pedidos`, `carrinhos`, `estoque_movimentacoes`, financeiro etc.).
- Tabelas de referência com `unique` que exigem seed idempotente:
  - `configuracoes.chave`
  - `feriados (data,escopo,uf)`
  - `outlet_motivos.slug`
  - `outlet_formas_pagamento.slug`
  - `formas_pagamento.nome` e `formas_pagamento.slug`
  - `centros_custo.slug`
  - `categorias_financeiras.slug`
  - `contas_financeiras.slug`
  - `categorias` (IDs estáveis definidos na seed)
- Dependências de FK importantes no fluxo de estoque:
  - `produtos.id_categoria -> categorias.id`
  - `produtos.id_fornecedor -> fornecedores.id`
  - `produto_variacoes.produto_id -> produtos.id`
  - `estoque.id_variacao -> produto_variacoes.id`
  - `estoque.id_deposito -> depositos.id`
  - `localizacoes_estoque.estoque_id -> estoque.id`
  - `localizacao_valores.localizacao_id -> localizacoes_estoque.id`
  - `localizacao_valores.dimensao_id -> localizacao_dimensoes.id`

## Cobertura de seeds (antes x depois)

### P0 (referência / pré-requisito)

- `acesso_usuarios`
  - Antes: sem seed e migration só criava em `testing`.
  - Depois: migration corrigida + `AcessoUsuariosSeeder` (IDs estáveis).
- `configuracoes`
  - Antes: `insert` puro (duplicava).
  - Depois: `upsert` por `chave`.
- `feriados`
  - Antes: dependia de serviço externo.
  - Depois: seed local idempotente (`upsert`) para ano atual e próximo.
- `formas_pagamento`
  - Antes: tabela criada por migration com `insert` único.
  - Depois: `FormasPagamentoSeeder` idempotente por `slug`.
- `outlet_motivos`, `outlet_formas_pagamento`
  - Antes: já idempotente.
  - Depois: mantido.
- `assistencia_defeitos`
  - Antes: idempotente.
  - Depois: mantido.
- `categorias`, `fornecedores`, `depositos`, `clientes`, `parceiros`, `parceiro_contatos`
  - Antes: majoritariamente `insert`/faker (não idempotente).
  - Depois: `upsert`/`updateOrCreate` com chaves naturais e dados mínimos estáveis.
- `areas_estoque`, `localizacao_dimensoes`
  - Antes: áreas existiam; dimensões sem seeder dedicado.
  - Depois: `AreasEstoqueSeeder` + `LocalizacaoDimensoesSeeder`.

### P1 (fluxo principal de estoque)

- `produtos`
  - Antes: dados randômicos e `insert` puro.
  - Depois: catálogo mínimo determinístico com `upsert` por `nome`.
- `produto_variacoes` e `produto_variacao_atributos`
  - Antes: referência/código randômicos e `insert`.
  - Depois: referências determinísticas (`PV-xxxx-yy`) + `updateOrInsert`.
- `estoque`
  - Antes: seed tentava colunas inexistentes (`corredor`, `prateleira`, `nivel`).
  - Depois: somente colunas válidas (`id_variacao`, `id_deposito`, `quantidade`) com `updateOrInsert`.
- `localizacoes_estoque` e `localizacao_valores`
  - Antes: seeder existente e idempotente.
  - Depois: mantido e integrado ao fluxo padrão.

### P2 / demo (transacionais)

- Seeders transacionais legados permanecem no projeto, mas não são executados por padrão no `DatabaseSeeder`.
- Fluxo padrão prioriza referência + dados mínimos de operação.
