<?php
declare(strict_types=1);

namespace Famedic\AdministrativeCertification;

/** Administrative CLI only. Never attached to a route or provider. */
final class AtixKeyCertification
{
    public static function removeTerminalLineEnding(string $value): string
    {
        return preg_replace('/\r?\n\z/', '', $value);
    }

    public static function evaluate(array $keys, string $old, string $new, bool $cache): array
    {
        foreach ([$old, $new] as $id) {
            if ($id === '' || strlen($id) > 256 || trim($id) !== $id || preg_match('/[\x00-\x1f\x7f]/', $id)) {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
        }
        if ($old === $new) { throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED'); }
        foreach ($keys as $value) {
            if (!is_string($value)) { throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED'); }
        }
        $newConfigured = array_key_exists($new, $keys) && $keys[$new] !== '';
        // Extra entries are conservatively flagged; no claim of retry/fallback execution.
        $additional = count($keys) > (array_key_exists($new, $keys) ? 1 : 0);
        return [
            'OLD_KEY_CONFIGURED' => array_key_exists($old, $keys),
            'NEW_KEY_CONFIGURED' => $newConfigured,
            'FALLBACK_DETECTED' => $additional,
            'CONFIG_CACHE_ACTIVE' => $cache,
        ];
    }

    public static function verifyProfile(string $root, array $profile): void
    {
        if (($profile['version'] ?? null) !== 1 || empty($profile['files']) || !is_array($profile['files'])) {
            throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
        }
        foreach ($profile['files'] as $relative => $expected) {
            if (!is_string($relative) || str_contains($relative, '..') || str_starts_with($relative, '/')
                || str_contains($relative, '\\') || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)) {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
            $path = $root.'/'.$relative;
            if (is_link($path) || !is_file($path) || !hash_equals($expected, hash_file('sha256', $path))) {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
        }
    }

    public static function run(array $argv): int
    {
        $baseBuffer = ob_get_level();
        ob_start();
        // Only this short-lived administrative CLI's diagnostic output is suppressed.
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        set_error_handler(static function (): never { throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED'); });
        try {
            if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || count($argv) !== 1 || !stream_isatty(STDIN) || !stream_isatty(STDERR)) {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
            if (filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN)) {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
            $root = dirname(__DIR__);
            $profile = json_decode(file_get_contents(__DIR__.'/atix-key-certification-profile.json'), true, 512, JSON_THROW_ON_ERROR);
            $required = ['config/atix.php', 'app/Http/Middleware/Api/V1/AuthenticateAtixHmac.php',
                'app/Services/Api/V1/Integrations/AtixHmacService.php', 'bootstrap/app.php', 'bootstrap/providers.php',
                'app/Providers/AppServiceProvider.php', 'routes/api.php', 'routes/api/v1.php', 'composer.lock',
                'vendor/symfony/console/Helper/QuestionHelper.php', 'vendor/symfony/console/Question/Question.php'];
            if (!is_array($profile) || array_diff($required, array_keys($profile['files'] ?? []))) {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
            self::verifyProfile($root, $profile);
            require_once $root.'/vendor/autoload.php';
            $input = new \Symfony\Component\Console\Input\ArgvInput([$argv[0]]);
            $output = new \Symfony\Component\Console\Output\StreamOutput(STDERR);
            $questions = new \Symfony\Component\Console\Helper\QuestionHelper;
            $ask = static function (string $prompt) use ($questions, $input, $output): string {
                $question = new \Symfony\Component\Console\Question\Question($prompt);
                $question->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
                $value = $questions->ask($input, $output, $question);
                if (!is_string($value)) { throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED'); }
                return self::removeTerminalLineEnding($value);
            };
            $old = $ask('Key ID retirado (entrada oculta): ');
            $new = $ask('Key ID vigente (entrada oculta): ');
            $app = require $root.'/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            if ($app['config']->get('app.env') !== 'staging' || $app['config']->get('atix.sandbox.algorithm') !== 'sha256') {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
            $service = $app->make(\App\Services\Api\V1\Integrations\AtixHmacService::class);
            $middleware = $app->make(\App\Http\Middleware\Api\V1\AuthenticateAtixHmac::class);
            if (get_class($service) !== \App\Services\Api\V1\Integrations\AtixHmacService::class
                || get_class($middleware) !== \App\Http\Middleware\Api\V1\AuthenticateAtixHmac::class) {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
            foreach ([[$service, 'app/Services/Api/V1/Integrations/AtixHmacService.php'],
                      [$middleware, 'app/Http/Middleware/Api/V1/AuthenticateAtixHmac.php']] as [$object, $relative]) {
                if (realpath((new \ReflectionClass($object))->getFileName()) !== realpath($root.'/'.$relative)) {
                    throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
                }
            }
            $route = $app['router']->getRoutes()->getByName('api.v1.integrations.atix.phone-lookups.store');
            if ($route === null || $route->uri() !== 'api/v1/integrations/atix/phone-lookups'
                || !in_array('POST', $route->methods(), true) || !in_array(\App\Http\Middleware\Api\V1\AuthenticateAtixHmac::class,
                    $app['router']->gatherRouteMiddleware($route), true)) {
                throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED');
            }
            $keys = $app['config']->get('atix.sandbox.keys');
            if (!is_array($keys)) { throw new \RuntimeException('CERTIFICATION_NOT_COMPLETED'); }
            $result = self::evaluate($keys, $old, $new, $app->configurationIsCached());
            unset($old, $new, $keys, $service, $middleware, $app);
            while (ob_get_level() > $baseBuffer) { ob_end_clean(); }
            restore_error_handler();
            fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
            return (!$result['OLD_KEY_CONFIGURED'] && $result['NEW_KEY_CONFIGURED']
                && !$result['FALLBACK_DETECTED'] && $result['CONFIG_CACHE_ACTIVE']) ? 0 : 1;
        } catch (\Throwable) {
            while (ob_get_level() > $baseBuffer) { ob_end_clean(); }
            restore_error_handler();
            // Empty output is NOT a negative finding and must remain PENDING.
            return 2;
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(AtixKeyCertification::run($argv));
}
