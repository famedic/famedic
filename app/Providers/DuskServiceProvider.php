<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class DuskServiceProvider extends ServiceProvider
{
    public function register(): void 
    {
        // Podemos registrar un alias o binding si es necesario
    }

    public function boot(): void
    {
        // Verificar DOS condiciones:
        // 1. Que estemos en entorno local/testing
        // 2. Que la clase Browser de Dusk exista (Dusk está instalado)
        if ($this->shouldLoadDuskMacros()) {
            $this->loadDuskMacros();
        }
    }

    protected function shouldLoadDuskMacros(): bool
    {
        return $this->app->environment('local', 'testing') 
            && class_exists(\Laravel\Dusk\Browser::class);
    }

    protected function loadDuskMacros(): void
    {
        // Importar las clases necesarias DENTRO de este método
        // para evitar errores en producción si no existen
        $browserClass = \Laravel\Dusk\Browser::class;
        
        $browserClass::macro('inputDate', function ($selector, $date) {
            $date = $date instanceof \Carbon\CarbonInterface ? $date : \Carbon\Carbon::parse($date);
            $this->resolver->findOrFail($selector)
                ->sendKeys([
                    $date->format('d'),
                    $date->format('m'),
                    $date->format('Y'),
                ]);

            return $this;
        });

        $browserClass::macro('clearInput', function ($selector) {
            $this->type($selector, [\Laravel\Dusk\OperatingSystem::onMac() ? \Facebook\WebDriver\WebDriverKeys::META : \Facebook\WebDriver\WebDriverKeys::CONTROL, 'a'])
                ->keys($selector, \Facebook\WebDriver\WebDriverKeys::BACKSPACE);

            return $this;
        });

        $browserClass::macro('clearDateInput', function ($selector) {
            $this->type($selector, [\Laravel\Dusk\OperatingSystem::onMac() ? \Facebook\WebDriver\WebDriverKeys::META : \Facebook\WebDriver\WebDriverKeys::CONTROL, 'a'])
                ->keys($selector, \Facebook\WebDriver\WebDriverKeys::BACKSPACE)
                ->keys($selector, \Facebook\WebDriver\WebDriverKeys::TAB)
                ->keys($selector, \Facebook\WebDriver\WebDriverKeys::BACKSPACE)
                ->keys($selector, \Facebook\WebDriver\WebDriverKeys::TAB)
                ->keys($selector, \Facebook\WebDriver\WebDriverKeys::BACKSPACE)
                ->keys($selector, \Facebook\WebDriver\WebDriverKeys::ARROW_LEFT)
                ->keys($selector, \Facebook\WebDriver\WebDriverKeys::ARROW_LEFT);

            return $this;
        });

        $browserClass::macro('disableFormValidation', function () {
            $this->script("
                document.querySelectorAll('form').forEach(form => {
                    form.noValidate = true;
                });
            ");

            return $this;
        });
    }
}