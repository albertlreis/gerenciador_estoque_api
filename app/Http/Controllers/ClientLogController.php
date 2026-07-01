<?php

namespace App\Http\Controllers;

use App\Services\AuditoriaLogService;
use App\Support\Auditoria\AuditoriaRedactor;
use App\Support\Logging\SierraLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientLogController extends Controller
{
    private const ALLOWED_KEYS = [
        'event_name',
        'event_type',
        'level',
        'module',
        'operation',
        'message',
        'stack',
        'component_stack',
        'context',
        'source',
        'lineno',
        'colno',
        'url',
        'route',
        'user_agent',
        'app_version',
        'request_id',
        'viewport_width',
        'viewport_height',
    ];

    public function store(Request $request, AuditoriaLogService $auditoria): JsonResponse
    {
        $this->validateClosedSchema($request);

        $validator = Validator::make($request->all(), [
            'event_name' => ['nullable', 'string', 'max:180'],
            'event_type' => ['required', 'string', 'in:error,unhandledrejection,react_error,handled_error,warning'],
            'level' => ['nullable', 'string', 'in:debug,info,warning,error'],
            'module' => ['nullable', 'string', 'max:80'],
            'operation' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string'],
            'stack' => ['nullable', 'string'],
            'component_stack' => ['nullable', 'string'],
            'context' => ['nullable', 'array'],
            'source' => ['nullable', 'string'],
            'lineno' => ['nullable', 'integer', 'min:0'],
            'colno' => ['nullable', 'integer', 'min:0'],
            'url' => ['nullable', 'string'],
            'route' => ['nullable', 'string'],
            'user_agent' => ['nullable', 'string'],
            'app_version' => ['nullable', 'string'],
            'request_id' => ['nullable', 'string'],
            'viewport_width' => ['nullable', 'integer', 'min:0'],
            'viewport_height' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated = $validator->validate();
        $requestId = $this->requestId($validated['request_id'] ?? $request->headers->get('X-Request-Id'));
        $request->headers->set('X-Request-Id', $requestId);
        $request->attributes->set('request_id', $requestId);
        $message = $this->str($validated['message'] ?? 'Erro JavaScript sem mensagem', 1000);
        $stack = $this->str($validated['stack'] ?? null, 6000);
        $componentStack = $this->str($validated['component_stack'] ?? null, 4000);
        $eventName = SierraLog::normalizeEventName(
            (string) ($validated['event_name'] ?? $this->defaultEventName($validated['event_type']))
        );
        $level = $this->level($validated['level'] ?? null, $validated['event_type']);
        $module = $this->str($validated['module'] ?? 'front', 80) ?: 'front';
        $operation = $this->str($validated['operation'] ?? $validated['event_type'], 120);
        $extraContext = AuditoriaRedactor::redact($validated['context'] ?? []);

        $metadata = AuditoriaRedactor::redact([
            'event_name' => $eventName,
            'event_type' => $validated['event_type'],
            'level' => $level,
            'module' => $module,
            'operation' => $operation,
            'request_id' => $requestId,
            'source' => $this->str($validated['source'] ?? null, 500),
            'lineno' => $validated['lineno'] ?? null,
            'colno' => $validated['colno'] ?? null,
            'url' => $this->str($validated['url'] ?? null, 500),
            'route' => $this->str($validated['route'] ?? null, 255),
            'app_version' => $this->str($validated['app_version'] ?? null, 120),
            'viewport_width' => $validated['viewport_width'] ?? null,
            'viewport_height' => $validated['viewport_height'] ?? null,
        ]);

        $context = AuditoriaRedactor::redact([
            'request_id' => $requestId,
            'service' => 'gerenciador-estoque-front',
            'event_name' => $eventName,
            'event_type' => $validated['event_type'],
            'level' => $level,
            'module' => $module,
            'operation' => $operation,
            'message' => $message,
            'stack' => $stack,
            'component_stack' => $componentStack,
            'user_agent' => $this->str($validated['user_agent'] ?? $request->userAgent(), 1000),
            'context' => $extraContext,
        ]);

        $auditoria->registrar([
            'tipo' => 'log',
            'categoria' => 'tecnico',
            'nivel' => $level,
            'modulo' => $module,
            'acao' => $operation ?: $eventName,
            'status' => $validated['event_type'],
            'label' => $message,
            'message' => $message,
            'source_system' => 'front',
            'source_kind' => 'browser_error',
            'source_table' => 'browser',
            'source_id' => (string) Str::uuid(),
            'origem' => 'browser',
            'route' => $this->str($validated['route'] ?? null, 255),
            'method' => 'CLIENT',
            'user_agent' => $this->str($validated['user_agent'] ?? $request->userAgent(), 1000),
            'metadata_json' => $metadata,
            'context_json' => $context,
            'raw_excerpt' => $stack ?: $componentStack,
            'retention_days' => 90,
        ]);

        SierraLog::browser($eventName, [
            'service' => 'gerenciador-estoque-front',
            'request_id' => $requestId,
            'source_system' => 'front',
            'source_kind' => 'browser_error',
            'event_type' => $validated['event_type'],
            'module' => $module,
            'operation' => $operation,
            'route' => $metadata['route'] ?? null,
            'url' => $metadata['url'] ?? null,
            'message' => $message,
            'context' => $extraContext,
        ], $level);

        return response()->json(['status' => 'accepted', 'request_id' => $requestId], 202)
            ->header('X-Request-Id', $requestId);
    }

    private function validateClosedSchema(Request $request): void
    {
        $unknown = array_values(array_diff(array_keys($request->all()), self::ALLOWED_KEYS));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'payload' => ['Campos nao permitidos: '.implode(', ', $unknown)],
            ]);
        }
    }

    private function requestId(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $value)) {
            return $value;
        }

        return (string) Str::uuid();
    }

    private function defaultEventName(string $eventType): string
    {
        return match ($eventType) {
            'react_error' => 'browser.react_error',
            'unhandledrejection' => 'browser.unhandledrejection',
            'handled_error' => 'browser.handled_error',
            'warning' => 'browser.warning',
            default => 'browser.error',
        };
    }

    private function level(?string $level, string $eventType): string
    {
        if (in_array($level, ['debug', 'info', 'warning', 'error'], true)) {
            return $level;
        }

        return $eventType === 'warning' ? 'warning' : 'error';
    }

    private function str(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = is_scalar($value)
            ? (string) $value
            : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return AuditoriaRedactor::truncateString(AuditoriaRedactor::redactString($value), $max);
    }
}
