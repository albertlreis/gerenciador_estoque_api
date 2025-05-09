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

