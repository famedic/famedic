<?php

namespace App\Support;

class AppEnvironmentLabel
{
    public static function shouldShowBadge(): bool
    {
        return ! app()->environment('production');
    }

    public static function current(): string
    {
        return match (app()->environment()) {
            'local' => 'LOCAL',
            'staging' => 'STAGING',
            'testing' => 'TESTING',
            'qa' => 'QA',
            default => strtoupper(app()->environment()),
        };
    }

    /**
     * @return array{color: string, tone: string}
     */
    public static function badgeStyle(): array
    {
        return match (app()->environment()) {
            'local' => ['color' => 'sky', 'tone' => 'info'],
            'staging' => ['color' => 'amber', 'tone' => 'warning'],
            'testing', 'qa' => ['color' => 'violet', 'tone' => 'info'],
            default => ['color' => 'zinc', 'tone' => 'info'],
        };
    }
}
