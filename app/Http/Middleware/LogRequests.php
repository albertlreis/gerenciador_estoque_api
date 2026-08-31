<?php

namespace App\Http\Middleware;

use App\Support\Logging\SierraLog;
use Closure;

class LogRequests
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (in_array($request->method(), ['POST', 'PUT', 'DELETE'])) {
            SierraLog::http('http.write_request', [
                'user_id' => $request->user()?->id,
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? $request->path(),
                'field_names' => $this->safeFieldNames(array_keys($request->all())),
                'item_count' => $this->itemCount($request->all()),
                'request_bytes' => (int) ($request->server('CONTENT_LENGTH') ?? 0),
                'content_type' => $request->header('Content-Type'),
                'status' => method_exists($response, 'status')
                    ? $response->status()
                    : (method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null),
            ]);
        }

        return $response;
    }

    private function safeFieldNames(array $fields): array
    {
        return array_values(array_filter($fields, static function ($field) {
            return !preg_match('/(?:password|senha|token|secret|email|observa|coment|note|cpf|cnpj|telefone|endereco)/i', (string) $field);
        }));
    }

    private function itemCount(array $input): int
    {
        foreach (['itens', 'items', 'produtos', 'variacoes'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                return count($input[$key]);
            }
        }

        return count($input);
    }
}
