<?php

namespace Wtyd\Alfil\App\Providers;

use Illuminate\Support\ServiceProvider;
use Wtyd\Alfil\Configuration\ConfigurationParser;
use Wtyd\Alfil\Configuration\ToolDetector;
use Wtyd\Alfil\Container\RegisterBindings;
use Wtyd\Alfil\Hooks\HookInstaller;
use Wtyd\Alfil\Hooks\HookStatusInspector;
use Wtyd\Alfil\Jobs\JobRegistry;
use Wtyd\Alfil\Output\Diagnostics\DiagnosticsCollector;
use Tests\Doubles\HookInstallerFake;
use Tests\Doubles\HookStatusInspectorFake;
use Tests\Doubles\ToolDetectorFake;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\Alfil\Utils\Printer;
use Wtyd\Alfil\Utils\Storage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // fdasdfas
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $register = new RegisterBindings();
        $register->register();

        // For php 7.1 self-update
        // $this->app->bind(Humbug\SelfUpdate\Updater::class, App\Updater\Updater::class);

        // Bind my custom BuildCommand
        $this->app->bind(
            \LaravelZero\Framework\Commands\BuildCommand::class,
            \Wtyd\Alfil\App\Commands\Zero\BuildCommand::class
        );

        // Feed the runtime diagnostics block (FEAT-14) the same version source
        // as `--version` (app('git.version'), stamped into the .phar by the
        // build). Without this the collector falls back to PrettyVersions, which
        // reports 'unknown' when githooks runs as a distributed dependency.
        $this->app->bind(DiagnosticsCollector::class, function () {
            return new DiagnosticsCollector(null, null, (string) config('app.version'));
        });

        $this->testsRegister();
    }

    /**
     * Register testing overrides.
     *
     * The APP_ENV const is setted in Tests\CreatesApplication
     *
     * @return void
     */
    /**
     * Register fake classes for testing.
     *
     * These MUST run during bootstrap (before Artisan instantiates commands)
     * so that commands receive fakes via constructor DI. ConsoleTestCase::setUp()
     * re-registers the same bindings but by that point command instances are cached.
     *
     * The APP_ENV const is set in Tests\CreatesApplication.
     */
    protected function testsRegister(): void
    {
        if (defined('APP_ENV') && APP_ENV === 'testing') {
            // Same relative root the other fakes use, so a command that looks the
            // configuration up (conf:check, hook) finds the one the test built
            // under testsDir instead of the project's own qa/alfil.php.
            $this->app->singleton(ConfigurationParser::class, function ($app) {
                return new ConfigurationParser(SystemTestCase::TESTS_PATH, $app->make(JobRegistry::class));
            });
            $this->app->singleton(HookInstaller::class, HookInstallerFake::class);
            $this->app->singleton(HookStatusInspector::class, HookStatusInspectorFake::class);
            $this->app->singleton(ToolDetector::class, ToolDetectorFake::class);
            // The suite asserts on what the commands print; left to itself the
            // Printer would colour or not depending on phpunit's stdout and CI.
            $this->app->bind(Printer::class, function () {
                return new Printer(false);
            });
            Storage::$disk = 'testing';
        }
    }
}
