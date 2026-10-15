{{-- Variables: $content (string), $indent (bool, optional) --}}
@php
    $content = trim((string) ($content ?? ''));
    $lines = collect(preg_split('/\R/u', $content) ?: [])
        ->map(fn ($line) => trim((string) $line))
        ->filter(fn ($line) => $line !== '')
        ->values();
    $indent = $indent ?? true;
@endphp
@if ($lines->isEmpty())
    <p class="instruction-line">—</p>
@else
    @foreach ($lines as $line)
        @php
            $isBullet = str_starts_with($line, '-') || str_starts_with($line, '•');
            $isNumbered = (bool) preg_match('/^\d+[\.)]\s/u', $line);
            $cleanLine = $isBullet ? trim(ltrim($line, "-• \t")) : $line;
        @endphp
        @if ($isBullet || $isNumbered)
            <table class="instruction-bullet-table">
                <tr>
                    <td class="bullet-mark">{{ $isNumbered ? '' : '•' }}</td>
                    <td>{{ $isNumbered ? $line : $cleanLine }}</td>
                </tr>
            </table>
        @else
            <p class="instruction-line">{{ $line }}</p>
        @endif
    @endforeach
@endif
