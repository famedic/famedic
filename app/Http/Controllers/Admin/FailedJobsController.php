<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

class FailedJobsController extends Controller
{
    public function index(Request $request)
    {
        $request->user()->administrator->hasPermissionTo('logs-general.manage') || abort(403);

        if (! Schema::hasTable('failed_jobs')) {
            return Inertia::render('Admin/FailedJobs', [
                'failedJobs' => [
                    'data' => [],
                    'links' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
                ],
                'filters' => $this->filters($request),
                'stats' => $this->emptyStats(),
                'queues' => [],
                'connections' => [],
                'selectedJob' => null,
                'tableExists' => false,
            ]);
        }

        $filters = $this->filters($request);
        $baseQuery = $this->filteredQuery($filters);

        $failedJobs = (clone $baseQuery)
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($job) => $this->presentJob($job));

        $selectedJob = null;
        if ($request->filled('failed_job')) {
            $selected = DB::table('failed_jobs')
                ->where('id', $request->integer('failed_job'))
                ->first();

            $selectedJob = $selected ? $this->presentJob($selected, true) : null;
        }

        return Inertia::render('Admin/FailedJobs', [
            'failedJobs' => $failedJobs,
            'filters' => $filters,
            'stats' => $this->stats(),
            'queues' => DB::table('failed_jobs')
                ->select('queue')
                ->distinct()
                ->orderBy('queue')
                ->pluck('queue')
                ->filter()
                ->values(),
            'connections' => DB::table('failed_jobs')
                ->select('connection')
                ->distinct()
                ->orderBy('connection')
                ->pluck('connection')
                ->filter()
                ->values(),
            'selectedJob' => $selectedJob,
            'tableExists' => true,
        ]);
    }

    private function filteredQuery(array $filters)
    {
        return DB::table('failed_jobs')
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $search = '%'.$filters['q'].'%';

                $query->where(function ($query) use ($search) {
                    $query->where('uuid', 'like', $search)
                        ->orWhere('connection', 'like', $search)
                        ->orWhere('queue', 'like', $search)
                        ->orWhere('payload', 'like', $search)
                        ->orWhere('exception', 'like', $search);
                });
            })
            ->when($filters['queue'] !== '', fn ($query) => $query->where('queue', $filters['queue']))
            ->when($filters['connection'] !== '', fn ($query) => $query->where('connection', $filters['connection']))
            ->when($filters['from'] !== '', fn ($query) => $query->where('failed_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when($filters['to'] !== '', fn ($query) => $query->where('failed_at', '<=', Carbon::parse($filters['to'])->endOfDay()));
    }

    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'queue' => trim((string) $request->query('queue', '')),
            'connection' => trim((string) $request->query('connection', '')),
            'from' => $this->dateFilter($request->query('from')),
            'to' => $this->dateFilter($request->query('to')),
            'failed_job' => $request->query('failed_job'),
        ];
    }

    private function dateFilter(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function stats(): array
    {
        $now = now();
        $latest = DB::table('failed_jobs')->orderByDesc('failed_at')->value('failed_at');

        return [
            'total' => DB::table('failed_jobs')->count(),
            'last_24_hours' => DB::table('failed_jobs')->where('failed_at', '>=', $now->copy()->subDay())->count(),
            'today' => DB::table('failed_jobs')->where('failed_at', '>=', $now->copy()->startOfDay())->count(),
            'latest_failed_at' => $latest ? Carbon::parse($latest)->toIso8601String() : null,
        ];
    }

    private function emptyStats(): array
    {
        return [
            'total' => 0,
            'last_24_hours' => 0,
            'today' => 0,
            'latest_failed_at' => null,
        ];
    }

    private function presentJob(object $job, bool $includeDetails = false): array
    {
        $payload = $this->decodePayload((string) $job->payload);
        $exception = (string) $job->exception;

        return [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'connection' => $job->connection,
            'queue' => $job->queue,
            'job_name' => $this->jobName($payload),
            'exception_summary' => $this->exceptionSummary($exception),
            'failed_at' => Carbon::parse($job->failed_at)->toIso8601String(),
            'payload_preview' => Str::limit((string) $job->payload, 500),
            'exception_preview' => Str::limit($exception, 1000),
            'payload' => $includeDetails ? Str::limit($this->prettyPayload((string) $job->payload), 12000) : null,
            'exception' => $includeDetails ? Str::limit($exception, 12000) : null,
        ];
    }

    private function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function jobName(array $payload): string
    {
        return (string) (
            data_get($payload, 'displayName')
            ?? data_get($payload, 'data.commandName')
            ?? data_get($payload, 'job')
            ?? 'Job sin nombre'
        );
    }

    private function exceptionSummary(string $exception): string
    {
        $line = collect(preg_split('/\R/', $exception) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->first(fn ($line) => $line !== '');

        return $line ? Str::limit($line, 220) : 'Sin excepción registrada';
    }

    private function prettyPayload(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $payload;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $payload;
    }
}
