<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\LaboratoryPurchase;
use App\Models\MurguiaSyncLog;
use App\Models\OnlinePharmacyPurchase;
use App\Models\PaymentAttempt;
use App\Models\PaymentLog;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MonitoringAiService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente interno de monitoreo para Famedic.
Tu trabajo es ayudar al equipo administrativo a entender qué ocurrió en la plataforma.
Debes responder de forma clara, breve y accionable.

Puedes ayudar con:
- resumen del día
- usuarios nuevos
- compras
- intentos de pago
- errores o fallas recientes
- carritos
- notificaciones
- integración Murguía
- tokens Efevoo
- actividad administrativa

Reglas:
- No inventes datos.
- Si no recibes datos suficientes, dilo claramente.
- Resume hallazgos importantes primero.
- Usa bullets cuando sea útil.
- Señala posibles alertas.
- No muestres datos sensibles completos.
- No expongas tokens, llaves API, contraseñas, correos completos o información privada innecesaria.
PROMPT;

    public function ask(string $question): string
    {
        $apiKey = config('services.openai.key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new \RuntimeException('La API de OpenAI no está configurada. Agrega OPENAI_API_KEY en el entorno.');
        }

        $context = $this->buildMonitoringContext();
        $contextJson = $this->truncateContext(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');

        $userMessage = "Pregunta del administrador:\n{$question}\n\nContexto operativo del sistema (datos reales, ya enmascarados):\n{$contextJson}";

        $response = Http::timeout((int) config('services.openai.timeout', 60))
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'temperature' => 0.2,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new \RuntimeException('OpenAI respondió con error: ' . Str::limit((string) $error, 300));
        }

        $answer = $response->json('choices.0.message.content');
        if (! is_string($answer) || trim($answer) === '') {
            throw new \RuntimeException('OpenAI no devolvió una respuesta válida.');
        }

        return trim($answer);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMonitoringContext(): array
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $yesterdayStart = $now->copy()->subDay()->startOfDay();
        $yesterdayEnd = $now->copy()->subDay()->endOfDay();

        $context = [
            'generated_at' => $now->toIso8601String(),
            'timezone' => (string) config('app.timezone', 'UTC'),
            'summary' => [
                'users_created_today' => $this->safeCountUsersBetween($todayStart, $now),
                'users_created_yesterday' => $this->safeCountUsersBetween($yesterdayStart, $yesterdayEnd),
            ],
        ];

        $context['transactions'] = $this->buildTransactionSummary($todayStart, $now);
        $context['purchases_today'] = $this->buildPurchasesSummary($todayStart, $now);
        $context['recent_payment_attempts'] = $this->buildRecentPaymentAttempts();
        $context['carts'] = $this->buildCartsSummary($todayStart, $now);
        $context['recent_murguia_sync'] = $this->buildRecentMurguiaSync();
        $context['recent_payment_logs'] = $this->buildRecentPaymentLogs();
        $context['recent_log_lines'] = $this->buildRecentLogLines();

        return $this->maskSensitiveData($context);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTransactionSummary(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('transactions')) {
            return ['available' => false];
        }

        $base = Transaction::query()->whereBetween('created_at', [$start, $end]);

        $summary = [
            'available' => true,
            'total_today' => (clone $base)->count(),
        ];

        if (Schema::hasColumn('transactions', 'payment_status')) {
            $successfulStatuses = ['captured', 'completed', 'paid', 'success', 'succeeded', 'credit'];
            $failedStatuses = ['failed', 'declined', 'refunded'];
            $pendingStatuses = ['pending'];

            $summary['successful_today'] = (clone $base)->whereIn('payment_status', $successfulStatuses)->count();
            $summary['failed_today'] = (clone $base)->whereIn('payment_status', $failedStatuses)->count();
            $summary['pending_today'] = (clone $base)->whereIn('payment_status', $pendingStatuses)->count();
        }

        $summary['recent'] = Transaction::query()
                ->orderByDesc('created_at')
                ->limit(5)
                ->get($this->transactionSelectColumns())
                ->map(fn (Transaction $t) => [
                    'id' => $t->id,
                    'payment_method' => $t->payment_method,
                    'payment_status' => $t->payment_status ?? null,
                    'gateway' => $t->gateway ?? null,
                    'gateway_status' => $t->gateway_status ?? null,
                    'amount_cents' => $t->transaction_amount_cents,
                    'reference_id' => $this->maskToken((string) ($t->reference_id ?? '')),
                    'created_at' => $t->created_at?->toIso8601String(),
                ])
                ->all();

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPurchasesSummary(Carbon $start, Carbon $end): array
    {
        $summary = [];

        if (Schema::hasTable('laboratory_purchases')) {
            $summary['laboratory'] = LaboratoryPurchase::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        if (Schema::hasTable('online_pharmacy_purchases')) {
            $summary['online_pharmacy'] = OnlinePharmacyPurchase::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRecentPaymentAttempts(): array
    {
        if (! Schema::hasTable('payment_attempts')) {
            return [];
        }

        return PaymentAttempt::query()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'gateway', 'status', 'amount_cents', 'processor_code', 'processor_message', 'reference', 'processed_at', 'created_at'])
            ->map(fn (PaymentAttempt $attempt) => [
                'id' => $attempt->id,
                'gateway' => $attempt->gateway,
                'status' => $attempt->status,
                'amount_cents' => $attempt->amount_cents,
                'processor_code' => $attempt->processor_code,
                'processor_message' => Str::limit((string) $attempt->processor_message, 160),
                'reference' => $this->maskToken((string) $attempt->reference),
                'processed_at' => $attempt->processed_at?->toIso8601String(),
                'created_at' => $attempt->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function transactionSelectColumns(): array
    {
        $columns = ['id', 'transaction_amount_cents', 'created_at'];

        foreach (['payment_method', 'payment_status', 'gateway', 'gateway_status', 'reference_id', 'payment_provider'] as $column) {
            if (Schema::hasColumn('transactions', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCartsSummary(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('carts')) {
            return ['available' => false];
        }

        $todayQuery = Cart::query()->whereBetween('updated_at', [$start, $end]);

        $summary = [
            'available' => true,
            'updated_today' => (clone $todayQuery)->count(),
            'active_today' => (clone $todayQuery)->displayStatusFilter('active')->count(),
            'abandoned_today' => (clone $todayQuery)->displayStatusFilter('abandoned')->count(),
            'completed_today' => (clone $todayQuery)->displayStatusFilter('completed')->count(),
            'recent_today' => Cart::query()
                ->with('user:id,name,paternal_lastname,email')
                ->withCount('items')
                ->whereBetween('updated_at', [$start, $end])
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get()
                ->map(fn (Cart $cart) => $this->formatCartForContext($cart))
                ->all(),
        ];

        if (Schema::hasTable('laboratory_appointments')) {
            $summary['appointment_pending_confirmation'] = Cart::query()
                ->appointmentPendingConfirmation()
                ->whereBetween('updated_at', [$start, $end])
                ->count();
            $summary['appointment_confirmed_pending_payment'] = Cart::query()
                ->appointmentConfirmedPendingPayment()
                ->whereBetween('updated_at', [$start, $end])
                ->count();
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCartForContext(Cart $cart): array
    {
        $user = $cart->user;

        $data = [
            'id' => $cart->id,
            'type' => $cart->type?->value ?? (string) $cart->type,
            'status' => $cart->status?->value ?? (string) $cart->status,
            'display_status' => $cart->displayStatusLabel(),
            'total' => $cart->total,
            'items_count' => $cart->items_count ?? $cart->items()->count(),
            'user' => $user ? [
                'id' => $user->id,
                'name' => trim("{$user->name} {$user->paternal_lastname}"),
                'email' => $user->email,
            ] : null,
            'updated_at' => $cart->updated_at?->toIso8601String(),
        ];

        if (Schema::hasTable('laboratory_appointments')) {
            $data['appointment_pending_confirmation'] = $cart->hasAppointmentPendingConfirmation();
            $data['appointment_confirmed_pending_payment'] = $cart->hasAppointmentConfirmedPendingPayment();
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRecentCarts(): array
    {
        if (! Schema::hasTable('carts')) {
            return [];
        }

        return Cart::query()
            ->with('user:id,name,paternal_lastname,email')
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Cart $cart) => $this->formatCartForContext($cart))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRecentMurguiaSync(): array
    {
        if (! Schema::hasTable('murguia_sync_logs')) {
            return [];
        }

        return MurguiaSyncLog::query()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'action', 'status', 'message', 'email', 'entry_type', 'created_at'])
            ->map(fn (MurguiaSyncLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'status' => $log->status,
                'message' => Str::limit((string) $log->message, 160),
                'email' => $log->email,
                'entry_type' => $log->entry_type,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRecentPaymentLogs(): array
    {
        if (! Schema::hasTable('payment_logs')) {
            return [];
        }

        return PaymentLog::query()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'order_id', 'provider', 'action', 'status', 'created_at'])
            ->map(fn (PaymentLog $log) => [
                'id' => $log->id,
                'order_id' => $this->maskToken((string) $log->order_id),
                'provider' => $log->provider,
                'action' => $log->action,
                'status' => $log->status,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRecentLogLines(): array
    {
        $path = config('logging.channels.single.path') ?? storage_path('logs/laravel.log');
        if (! is_string($path) || ! File::exists($path)) {
            return [];
        }

        try {
            $lines = $this->readLastLines($path, 200);
        } catch (\Throwable) {
            return [];
        }

        $relevant = collect($lines)
            ->filter(fn (array $line) => preg_match('/\.(ERROR|CRITICAL|WARNING|ALERT|EMERGENCY):/i', $line['text'] ?? '') === 1)
            ->take(-10)
            ->values()
            ->map(fn (array $line) => [
                'line' => $line['number'] ?? null,
                'text' => Str::limit((string) ($line['text'] ?? ''), 300),
            ])
            ->all();

        return $relevant;
    }

    /**
     * @return list<array{number: int, text: string}>
     */
    private function readLastLines(string $path, int $lines = 200): array
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key() + 1;
        $file = null;

        $content = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $startLine = max(1, $totalLines - $lines);
        $currentLine = 0;

        while (($line = fgets($handle)) !== false) {
            $currentLine++;
            if ($currentLine >= $startLine) {
                $content[] = [
                    'number' => $currentLine,
                    'text' => rtrim($line),
                ];
            }
        }

        fclose($handle);

        return $content;
    }

    private function safeCountUsersBetween(Carbon $start, Carbon $end): int
    {
        if (! Schema::hasTable('users')) {
            return 0;
        }

        return User::query()->whereBetween('created_at', [$start, $end])->count();
    }

    /**
     * @param  mixed  $data
     * @return mixed
     */
    private function maskSensitiveData(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map(fn ($value) => $this->maskSensitiveData($value), $data);
        }

        if (! is_string($data) || $data === '') {
            return $data;
        }

        $masked = $data;

        $masked = preg_replace_callback(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            fn (array $matches) => $this->maskEmail($matches[0]),
            $masked
        ) ?? $masked;

        $masked = preg_replace_callback(
            '/\b(?:\+?\d[\d\s\-().]{7,}\d)\b/',
            fn (array $matches) => $this->maskPhone($matches[0]),
            $masked
        ) ?? $masked;

        if (preg_match('/(?:token|bearer|api[_-]?key|password|secret)/i', $masked) === 1) {
            return '[dato sensible omitido]';
        }

        if (strlen($masked) > 80 && preg_match('/^[A-Za-z0-9_\-]{40,}$/', $masked) === 1) {
            return $this->maskToken($masked);
        }

        return $masked;
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = Str::substr($local, 0, min(2, strlen($local)));

        return $visible . '***@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '***';
        }

        return '***' . substr($digits, -4);
    }

    private function maskToken(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strlen($value) <= 8) {
            return '***';
        }

        return Str::substr($value, 0, 4) . '…' . Str::substr($value, -2);
    }

    private function truncateContext(string $context): string
    {
        $max = (int) config('services.openai.max_context_chars', 12000);
        if (strlen($context) <= $max) {
            return $context;
        }

        return Str::substr($context, 0, $max) . "\n… [contexto truncado]";
    }
}
