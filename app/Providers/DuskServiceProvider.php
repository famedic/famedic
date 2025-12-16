<?php

namespace App\Providers;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\ServiceProvider;
use Laravel\Dusk\Browser;
use Laravel\Dusk\OperatingSystem;

class DuskServiceProvider extends ServiceProvider
{
    public function register(): void {
        // Solo registrar en desarrollo/testing
        if ($this->app->environment('local', 'testing')) {
            $this->registerDuskMacros();
        }
    }

    public function boot(): void
    {
        // Solo boot en desarrollo/testing
        if ($this->app->environment('local', 'testing')) {
            $this->bootDuskMacros();
        }
        Browser::macro('inputDate', function ($selector, $date) {
            $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
            $this->resolver->findOrFail($selector)
                ->sendKeys([
                    $date->format('d'),
                    $date->format('m'),
                    $date->format('Y'),
                ]);

            return $this;
        });

        Browser::macro('clearInput', function ($selector) {
            $this->type($selector, [OperatingSystem::onMac() ? WebDriverKeys::META : WebDriverKeys::CONTROL, 'a'])
                ->keys($selector, WebDriverKeys::BACKSPACE);

            return $this;
        });

        Browser::macro('clearDateInput', function ($selector) {
            $this->type($selector, [OperatingSystem::onMac() ? WebDriverKeys::META : WebDriverKeys::CONTROL, 'a'])
                ->keys($selector, WebDriverKeys::BACKSPACE)
                ->keys($selector, WebDriverKeys::TAB)
                ->keys($selector, WebDriverKeys::BACKSPACE)
                ->keys($selector, WebDriverKeys::TAB)
                ->keys($selector, WebDriverKeys::BACKSPACE)
                ->keys($selector, WebDriverKeys::ARROW_LEFT)
                ->keys($selector, WebDriverKeys::ARROW_LEFT);

            return $this;
        });

        Browser::macro('disableFormValidation', function () {
            $this->script("
                document.querySelectorAll('form').forEach(form => {
                    form.noValidate = true;
                });
            ");

            return $this;
        });
    }

    protected function registerDuskMacros(): void
    {
        // Requerir clase solo si existe
        if (class_exists(\Laravel\Dusk\Browser::class)) {
            require_once base_path('app/Providers/DuskMacros.php');
        }
    }

    protected function bootDuskMacros(): void
    {
        // Nada aquí, todo está en registerDuskMacros
    }
}
