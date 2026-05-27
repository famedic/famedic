<?php

namespace App\Support;

use App\Exceptions\ChromiumNotAvailableException;
use Spatie\Browsershot\Browsershot;

final class BrowsershotConfigurator
{
    /**
     * @throws ChromiumNotAvailableException
     */
    public static function apply(Browsershot $browsershot): Browsershot
    {
        $chromePath = self::requireChromePath();
        $userDataDir = rtrim((string) config('famedic.browsershot.chrome_user_data_dir', '/tmp/.chromium'), '/');

        self::ensureChromeDirectories($userDataDir);

        $browsershot->noSandbox();

        // Puppeteer 22+ por defecto usa headless "shell" (chrome-headless-shell en ~/.cache).
        // Con newHeadless() usa Chromium completo vía executablePath (el del sistema en Forge/Docker).
        if (method_exists($browsershot, 'newHeadless')) {
            $browsershot->newHeadless();
        } else {
            $browsershot->setOption('newHeadless', true);
        }

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

        $browsershot->setChromePath($chromePath);

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
                'PUPPETEER_EXECUTABLE_PATH' => $chromePath,
                'PUPPETEER_SKIP_CHROMIUM_DOWNLOAD' => 'true',
                'PUPPETEER_SKIP_DOWNLOAD' => 'true',
            ]);
        }

        return $browsershot;
    }

    /**
     * @throws ChromiumNotAvailableException
     */
    public static function requireChromePath(): string
    {
        $configured = config('famedic.browsershot.chrome_path');
        $resolved = self::resolveChromePath($configured);

        if ($resolved !== null) {
            return $resolved;
        }

        throw ChromiumNotAvailableException::forPdfGeneration(
            is_string($configured) && $configured !== '' ? $configured : null,
        );
    }

    public static function resolveChromePath(mixed $configured = null): ?string
    {
        $candidates = [];

        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }

        foreach ([
            'chromium-browser',
            'chromium',
            'google-chrome-stable',
            'google-chrome',
        ] as $command) {
            $fromPath = self::which($command);
            if ($fromPath !== null) {
                $candidates[] = $fromPath;
            }
        }

        $candidates = array_merge($candidates, [
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/snap/bin/chromium',
        ]);

        foreach (array_unique($candidates) as $path) {
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
            if (self::isExecutableFile($command)) {
                return $command;
            }

            return null;
        }

        $paths = ['/usr/bin', '/usr/local/bin', '/bin', '/snap/bin'];
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
