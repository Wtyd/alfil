<?php

namespace Wtyd\Alfil\App\Commands;

use Exception;
use LaravelZero\Framework\Commands\Command;
use Wtyd\Alfil\Configuration\ConfigurationParser;
use Wtyd\Alfil\Configuration\ConfigurationResult;
use Wtyd\Alfil\Configuration\Exception\ConfigurationFileNotFoundException;
use Wtyd\Alfil\Hooks;
use Wtyd\Alfil\Hooks\HookInstaller;
use Wtyd\Alfil\Utils\Platform;
use Wtyd\Alfil\Utils\Printer;
use Wtyd\Alfil\Utils\Storage;

class CreateHookCommand extends Command
{
    protected $signature = 'hook
                            {hook=pre-commit : The hook to install (default: pre-commit). When using v3 config, installs all hooks defined in the config.}
                            {scriptFile? : Custom script file for legacy mode}
                            {--legacy : Force legacy installation (.git/hooks/ instead of core.hooksPath)}
                            {--config= : Path to configuration file}';

    protected $description = 'Install git hooks. With v3 config, uses core.hooksPath and installs all configured hooks.';

    protected Printer $printer;

    private ConfigurationParser $parser;

    private HookInstaller $installer;

    public function __construct(Printer $printer, ConfigurationParser $parser, HookInstaller $installer)
    {
        $this->printer = $printer;
        $this->parser = $parser;
        $this->installer = $installer;
        parent::__construct();
    }

    public function handle()
    {
        $hook = strval($this->argument('hook'));

        // If a custom script file is provided, always use legacy mode
        if (!empty($this->argument('scriptFile'))) {
            return $this->handleLegacy();
        }

        // If the hook argument is not a valid git hook, report error
        // (catches cases like: hook MyScript.php where scriptFile is missing)
        if (!Hooks::validate($hook)) {
            $this->printer->error("'$hook' is not a valid git hook. Available hooks are:");
            $this->printer->error(implode(', ', Hooks::HOOKS));
            return 1;
        }

        if ($this->option('legacy')) {
            return $this->handleLegacy();
        }

        return $this->handleV3();
    }

    private function handleV3(): int
    {
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);
        } catch (ConfigurationFileNotFoundException $e) {
            // No --config and nothing in the default locations: the minimal
            // install still has a point. An explicit path that does not exist
            // is an error, not a reason to install a hook pointing at it
            // (BUG-36).
            if ($configFile === '') {
                return $this->handleLegacy();
            }
            $this->printer->error($e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            $this->printer->error($e->getMessage());
            return 1;
        }

        if (!$this->reportValidation($config)) {
            return 1;
        }

        $hooks = $config->getHooks();

        if ($hooks === null || empty($hooks->getEvents())) {
            $this->printer->warning("No hooks defined in configuration. Nothing to install.");
            return 0;
        }

        $events = $hooks->getEvents();
        $command = $hooks->getCommand();
        $scriptConfig = $this->scriptConfigPath($configFile);
        $created = $this->installer->install($events, $command, $scriptConfig);

        foreach ($created as $path) {
            $event = basename($path);
            $this->printer->success("Hook $event installed");
        }

        $this->info("  hooks path: .githooks (configured via core.hooksPath)");
        if ($scriptConfig !== '') {
            $this->info("  config: $scriptConfig (baked into the hook scripts as --config)");
        }

        return 0;
    }

    /**
     * The parser's verdict decides the install the way it decides a run
     * (BUG-37): errors refuse it, warnings are reported before it, as
     * conf:check and hook:run report them. Returns false when refused.
     */
    private function reportValidation(ConfigurationResult $config): bool
    {
        $validation = $config->getValidation();

        foreach ($validation->getErrors() as $error) {
            $this->printer->resultError($error);
        }
        if ($config->hasErrors()) {
            return false;
        }

        foreach ($validation->getWarnings() as $warning) {
            $this->printer->resultWarning($warning);
        }

        return true;
    }

    /**
     * BUG-31: the scripts must run the configuration they were installed from,
     * not whatever githooks.php the CWD resolves to at each trigger. Empty when
     * no --config was given (default lookup, unchanged). A file outside the
     * repository can only be baked as an absolute path other clones will not
     * have — say so.
     */
    private function scriptConfigPath(string $configFile): string
    {
        if ($configFile === '') {
            return '';
        }

        $scriptConfig = $this->installer->scriptConfigPath($configFile);
        if (Platform::isAbsolutePath($scriptConfig)) {
            $this->printer->warning(
                "--config '$configFile' is outside the repository: the hooks reference the absolute path "
                . "'$scriptConfig' and will not work for other clones."
            );
        }

        return $scriptConfig;
    }

    /**
     * Git < 2.9 style install: one script copied into .git/hooks/. Also the
     * fallback when there is no configuration to read at all.
     */
    private function handleLegacy(): int
    {
        $hook = strval($this->argument('hook'));
        $scriptFile = strval($this->argument('scriptFile'));

        if (!Hooks::validate($hook)) {
            $this->printer->error("'$hook' is not a valid git hook. Available hooks are:");
            $this->printer->error(implode(', ', Hooks::HOOKS));
            return 1;
        }

        if (!empty($scriptFile)) {
            return $this->installCustomScript($hook, $scriptFile);
        }

        try {
            $destiny = ".git/hooks/$hook";

            if (Storage::exists($destiny)) {
                Storage::delete($destiny);
            }

            Storage::put($destiny, $this->legacyScript());
            Storage::chmod($destiny, 0755);

            $this->printer->success("Hook $hook created");
            return 0;
        } catch (\Throwable $th) {
            $this->printer->error("Error installing hook $hook");
            return 1;
        }
    }

    /**
     * `--config` travels along for the same reason it does in the core.hooksPath
     * scripts (BUG-31): each trigger must run the configuration the hook was
     * installed from, not whatever the working directory resolves to.
     */
    private function legacyScript(): string
    {
        $config = $this->scriptConfigPath(strval($this->option('config')));
        $configFlag = $config === '' ? '' : " --config='$config'";

        $command = "hook:run$configFlag \"$(basename \"$0\")\"";

        return "#!/bin/sh\n# Generated by Alfil — do not edit manually\n"
            . "php vendor/bin/alfil $command\n";
    }

    private function installCustomScript(string $hook, string $scriptFile): int
    {
        if (!Storage::exists($scriptFile)) {
            $this->printer->error("$scriptFile file not found");
            return 1;
        }

        try {
            $destiny = ".git/hooks/$hook";

            if (Storage::exists($destiny)) {
                Storage::delete($destiny);
            }

            Storage::copy($scriptFile, $destiny);
            Storage::chmod($destiny, 0755);

            $this->printer->success("Hook $hook created with custom script $scriptFile");
            return 0;
        } catch (\Throwable $th) {
            $this->printer->error("Error copying $scriptFile to $hook");
            return 1;
        }
    }
}
