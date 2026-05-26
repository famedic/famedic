<?php

namespace App\Support;

use Spatie\Browsershot\Browsershot;

final class BrowsershotConfigurator
{
    public static function apply(Browsershot $browsershot): Browsershot
    {
        $browsershot->noSandbox();

        $chromePath = self::resolveChromePath(config('famedic.browsershot.chrome_path'));
        $userDataDir = rtrim((string) config('famedic.browsershot.chrome_user_data_dir', '/tmp/.chromium'), '/');

        self::ensureChromeDirectories($userDataDir);

        $nodeBinary = self::resolveExecutable(
            config('famedic.browsershot.node_binary'),
            'node',
        );
        $npmBinary = self::resolveExecutable(
            config('famedic.browsershot.npm_binary'),
            'npm',
        );

        if ($nodeBinary !== null) {
            $browsershot->setNodeBinary($nodeBinary);
        }

        if ($npmBinary !== null) {
            $browsershot->setNpmBinary($npmBinary);
        }

        $nodeModules = base_path('node_modules');
        if (is_dir($nodeModules) && method_exists($browsershot, 'setNodeModulePath')) {
            $browsershot->setNodeModulePath($nodeModules);
        }

        if ($chromePath !== null) {
            $browsershot->setChromePath($chromePath);
        }

        $browsershot->addChromiumArguments([
            'disable-dev-shm-usage',
            'disable-gpu',
            'disable-software-rasterizer',
            'disable-extensions',
            'disable-crash-reporter',
            'no-crashpad',
            'crash-dumps-dir='.$userDataDir.'/crashdumps',
            'user-data-dir='.$userDataDir.'/profile',
        ]);

        if (method_exists($browsershot, 'setEnvironmentOptions')) {
            $browsershot->setEnvironmentOptions([
                'XDG_CONFIG_HOME' => $userDataDir,
                'XDG_CACHE_HOME' => $userDataDir,
                'HOME' => $userDataDir,
                'PUPPETEER_EXECUTABLE_PATH' => $chromePath ?? '/usr/bin/chromium',
                'PUPPETEER_SKIP_CHROMIUM_DOWNLOAD' => 'true',
            ]);
        }

        return $browsershot;
    }

    protected static function resolveChromePath(mixed $configured): ?string
    {
        $candidates = array_filter([
            is_string($configured) && $configured !== '' ? $configured : null,
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
        ]);

        foreach ($candidates as $path) {
            if (self::isExecutableFile($path)) {
                return $path;
            }
        }

        return null;
    }

    protected static function ensureChromeDirectories(string $baseDir): void
    {
        foreach ([
            $baseDir,
            $baseDir.'/profile',
            $baseDir.'/crashdumps',
            $baseDir.'/Crashpad',
        ] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
    }

    protected static function resolveExecutable(mixed $configured, string $fallback): ?string
    {
        $candidate = is_string($configured) && $configured !== '' ? $configured : $fallback;

        if (self::isExecutableFile($candidate)) {
            return $candidate;
        }

        $resolved = self::which($candidate);
        if ($resolved !== null) {
            return $resolved;
        }

        foreach (["/usr/bin/{$fallback}", "/usr/local/bin/{$fallback}"] as $path) {
            if (self::isExecutableFile($path)) {
                return $path;
            }
        }

        return null;
    }

    protected static function which(string $command): ?string
    {
        if (str_contains($command, '/')) {
            return null;
        }

        $paths = ['/usr/bin', '/usr/local/bin', '/bin'];
        $pathEnv = getenv('PATH');
        if (is_string($pathEnv) && $pathEnv !== '') {
            $paths = array_merge(explode(':', $pathEnv), $paths);
        }

        foreach (array_unique($paths) as $dir) {
            $full = rtrim($dir, '/').'/'.$command;
            if (self::isExecutableFile($full)) {
                return $full;
            }
        }

        return null;
    }

    protected static function isExecutableFile(string $path): bool
    {
        return str_starts_with($path, '/')
            && is_file($path)
            && is_executable($path);
    }
}
