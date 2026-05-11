# 📦 API de Estoque - Sistema ERP/CRM

Esta API é responsável pelo controle de produtos, categorias, depósitos, movimentações de estoque, pedidos e variações no sistema ERP/CRM.

## 🚀 Tecnologias Utilizadas

* [Laravel 10+](https://laravel.com/)
* [PHP 8.2+](https://www.php.net/)
* [PostgreSQL](https://www.postgresql.org/)
* [Spatie Laravel Query Builder](https://spatie.be/docs/laravel-query-builder/)
* [Docker](https://www.docker.com/) (opcional)

## 📁 Estrutura de Pastas Relevante

```sh
app/
├── Http/
│   ├── Controllers/       # ProdutoController, PedidoController, etc.
├── Models/                # Produto, Categoria, Pedido, etc.
routes/
└── api.php                # Rotas da API
```

## ⚙️ Instalação

1. Clone o repositório e acesse o diretório:

```bash
git clone https://github.com/seu-usuario/backend-estoque.git
cd backend-estoque
```

2. Instale as dependências:

```bash
composer install
```

3. Copie o `.env.example` e configure:

```bash
cp .env.example .env
php artisan key:generate
```

4. Configure o banco de dados e execute as migrations:

```bash
php artisan migrate --seed
```

5. Inicie o servidor:

```bash
php artisan serve
```

## 🧾 Recursos Disponíveis

### Produtos

* CRUD de produtos com suporte a imagens, variações e custo

### Categorias e Subcategorias

* Organização hierárquica dos produtos

### Depósitos

* Cadastro de múltiplos depósitos físicos ou virtuais

### Movimentações

* Entrada e saída de produtos com justificativas e origem

### Pedidos

* Lançamento de pedidos com vinculação a variações

## 🔧 Variáveis de Ambiente

```env
APP_NAME=ERPStock
APP_URL=http://localhost:8001
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=erp_estoque
DB_USERNAME=postgres
DB_PASSWORD=secret
```

## 🧪 Testes

Use ferramentas como Insomnia, Postman ou Swagger para testar os seguintes endpoints principais:

| Método | Rota               | Descrição                       |
| ------ | ------------------ | ------------------------------- |
| GET    | /api/produtos      | Lista de produtos               |
| POST   | /api/produtos      | Criação de produto              |
| GET    | /api/categorias    | Lista de categorias             |
| GET    | /api/pedidos       | Lista de pedidos                |
| POST   | /api/pedidos       | Criação de pedido com variações |
| POST   | /api/movimentacoes | Lançamento de entrada ou saída  |

## Conta Azul OAuth

Configure as variáveis `CONTA_AZUL_*` antes de iniciar a integração com a Conta Azul.

```env
CONTA_AZUL_CLIENT_ID=
CONTA_AZUL_CLIENT_SECRET=
CONTA_AZUL_REDIRECT_URI=http://localhost:8004/api/v1/integrations/conta-azul/callback
CONTA_AZUL_AUTH_URL=https://auth.contaazul.com
CONTA_AZUL_AUTHORIZE_PATH=/login
CONTA_AZUL_TOKEN_PATH=/oauth2/token
CONTA_AZUL_BASE_URL=https://api-v2.contaazul.com
CONTA_AZUL_SCOPE="openid profile aws.cognito.signin.user.admin"
CONTA_AZUL_OAUTH_FRONT_REDIRECT=http://localhost:5173/integracoes/conta-azul
```

A `CONTA_AZUL_REDIRECT_URI` precisa ser exatamente igual à URL cadastrada no Portal do Desenvolvedor da Conta Azul. Qualquer divergência entre a URL cadastrada e a URL enviada no OAuth pode causar erro `invalid_grant` ou falha de redirecionamento.
### Checklist de producao Conta Azul

1. Crie uma aplicacao no Portal do Desenvolvedor da Conta Azul e cadastre a callback publica, por exemplo `https://estoque.sierra.acadsoft.com.br/api/v1/integrations/conta-azul/callback`.
2. Configure as variaveis acima no ambiente do backend e rode `php artisan config:clear && php artisan config:cache`.
3. Rode as migrations, mantenha o queue worker ativo e confirme que o scheduler executa `conta-azul:refresh-tokens` e `conta-azul:reconciliar --todos`.
4. No front, acesse `/integracoes/conta-azul`, use **Conectar (OAuth)** e depois **Testar conexao**.
5. Importe pessoas, produtos, vendas, titulos e notas; rode conciliacao; resolva pendencias pela tabela da tela de integracao.
6. Habilite exportacao automatica apenas depois de validar payloads reais de clientes, produtos, vendas, titulos e baixas contra uma conta de teste/producao controlada.

Notas fiscais da Conta Azul estao tratadas como fluxo somente leitura nesta versao: o sistema importa para staging e permite ignorar pendencias, mas bloqueia vinculo manual fiscal ate o desenho de emissao/vinculo fiscal ser fechado.
