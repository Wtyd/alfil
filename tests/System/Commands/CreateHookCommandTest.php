<?php

namespace Tests\System\Commands;

use Tests\Doubles\HookInstallerFake;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\Alfil\Hooks\HookInstaller;
use Wtyd\Alfil\Utils\Storage;

/**
 * Testing Wtyd\Alfil\App\Commands\CreateHookCommand;
 */
class CreateHookCommandTest extends SystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->copyDefaultPrecommitToTestDirectory();
        Storage::makeDirectory('.git/hooks', 0777, true);
    }

    /**
     * Copy the 'hooks' directory of the application with de default script for the hooks ('hooks/precommit.php') to the root of the temporal
     * directory for tests.
     *
     * @return void
     */
    protected function copyDefaultPrecommitToTestDirectory()
    {
        Storage::makeDirectory('/hooks', 0777, true);
        shell_exec('cp -r hooks ' . $this->path);
    }

    /** @test */
    function it_creates_default_script_for_precommit_when_is_called_without_arguments()
    {
        $this->artisan('hook --legacy')
            ->containsStringInOutput('Hook pre-commit created')
            ->assertExitCode(0);

        $this->assertFileExists($this->path . '/.git/hooks/pre-commit');
    }

    public function hooksProvider()
    {
        return [
            'applypatch-msg' => ['applypatch-msg'],
            'pre-applypatch' => ['pre-applypatch'],
            'post-applypatch' => ['post-applypatch'],
            'pre-commit' => ['pre-commit'],
            'pre-merge-commit' => ['pre-merge-commit'],
            'prepare-commit-msg' => ['prepare-commit-msg'],
            'commit-msg' => ['commit-msg'],
            'post-commit' => ['post-commit'],
            'pre-rebase' => ['pre-rebase'],
            'post-checkout' => ['post-checkout'],
            'post-merge' => ['post-merge'],
            'pre-push' => ['pre-push'],
            'pre-receive' => ['pre-receive'],
            'update' => ['update'],
            'proc-receive' => ['proc-receive'],
            'post-receive' => ['post-receive'],
            'post-update' => ['post-update'],
            'reference-transaction' => ['reference-transaction'],
            'push-to-checkout' => ['push-to-checkout'],
            'pre-auto-gc' => ['pre-auto-gc'],
            'post-rewrite' => ['post-rewrite'],
            'sendemail-validate' => ['sendemail-validate'],
            'fsmonitor-watchman' => ['fsmonitor-watchman'],
            'p4-changelist' => ['p4-changelist'],
            'p4-prepare-changelist' => ['p4-prepare-changelist'],
            'p4-post-changelist' => ['p4-post-changelist'],
            'p4-pre-submit' => ['p4-pre-submit'],
            'post-index-change' => ['post-index-change'],
        ];
    }

    /**
     * @test
     * @dataProvider hooksProvider
     */
    function it_creates_default_script_in_the_hook_passed_as_argument($hook)
    {
        $this->artisan("hook $hook --legacy")
            ->containsStringInOutput("Hook $hook created")
            ->assertExitCode(0);

        $this->assertFileExists($this->path . "/.git/hooks/$hook");
    }

    /**
     * @test
     * Only is tested pre-push hook but it could be any hook.
     */
    function it_sets_a_custom_script_as_some_hook()
    {
        $hookContent = 'my custom script';
        $scriptFile = 'MyScript.php';
        Storage::put($scriptFile, $hookContent);

        $this->artisan("hook pre-push $scriptFile")
            ->containsStringInOutput("Hook pre-push created")
            ->assertExitCode(0);

        $this->assertFileExists($this->path . "/.git/hooks/pre-push", $scriptFile);
    }

    /** @test */
    function it_shows_an_error_message_when_is_setted_a_custom_script_without_specifying_the_hook()
    {
        $hookContent = 'my custom script';
        $scriptFilePath = $this->path . '/MyScript.php';
        file_put_contents($scriptFilePath, $hookContent);

        $supportedHooks = [
            'applypatch-msg',
            'pre-applypatch',
            'post-applypatch',
            'pre-commit',
            'pre-merge-commit',
            'prepare-commit-msg',
            'commit-msg',
            'post-commit',
            'pre-rebase',
            'post-checkout',
            'post-merge',
            'pre-push',
            'pre-receive',
            'update',
            'proc-receive',
            'post-receive',
            'post-update',
            'reference-transaction',
            'push-to-checkout',
            'pre-auto-gc',
            'post-rewrite',
            'sendemail-validate',
            'fsmonitor-watchman',
            'p4-changelist',
            'p4-prepare-changelist',
            'p4-post-changelist',
            'p4-pre-submit',
            'post-index-change',
        ];

        $supportedHooks2String = implode(', ', $supportedHooks);
        $this->artisan("hook $scriptFilePath")
            ->containsStringInOutput("'$scriptFilePath' is not a valid git hook. Available hooks are:")
            ->containsStringInOutput($supportedHooks2String)
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . "/.git/hooks/pre-commit", $scriptFilePath);
    }

    /** @test */
    function legacy_mode_overwrites_existing_hook_file()
    {
        Storage::put('.git/hooks/pre-commit', 'stale content');

        $this->artisan('hook --legacy')
            ->containsStringInOutput('Hook pre-commit created')
            ->assertExitCode(0);

        $this->assertFileExists($this->path . '/.git/hooks/pre-commit');
        $this->assertStringNotEqualsFile($this->path . '/.git/hooks/pre-commit', 'stale content');
    }

    /** @test */
    function reports_error_when_custom_script_file_does_not_exist()
    {
        $this->artisan('hook pre-push missing-script.php')
            ->containsStringInOutput('missing-script.php file not found')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . '/.git/hooks/pre-push');
    }

    /** @test */
    function v3_mode_installs_all_configured_hooks_in_githooks_directory()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa'], 'pre-push' => ['qa']])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("hook pre-commit --config=$configPath")
            ->assertExitCode(0);

        $this->assertFileExists($this->path . '/.githooks/pre-commit');
        $this->assertFileExists($this->path . '/.githooks/pre-push');

        /** @var HookInstallerFake $installer */
        $installer = $this->app->make(HookInstaller::class);
        $this->assertSame(1, $installer->configureHooksPathCalls);
    }

    /**
     * @test
     * BUG-31: the scripts carry the install config, relative to the repository
     * root, so every trigger runs that configuration and a committed .githooks/
     * still works for other clones.
     */
    function v3_mode_bakes_the_config_path_relative_to_the_repository_root_into_the_hook_scripts()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa']])
            ->buildInFileSystem();
        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("hook pre-commit --config=$configPath")
            ->containsStringInOutput('config: githooks.php (baked into the hook scripts as --config)')
            ->assertExitCode(0);

        $this->assertStringContainsString(
            " hook:run --config='githooks.php' \"$(basename \"$0\")\" \"$@\"",
            (string) file_get_contents($this->path . '/.githooks/pre-commit')
        );
    }

    /** @test BUG-31: a config outside the repository is baked absolute — and the install says so. */
    function v3_mode_warns_and_bakes_an_absolute_path_when_the_config_is_outside_the_repository()
    {
        $outside = sys_get_temp_dir() . '/githooks_outside_' . uniqid();
        mkdir($outside, 0755, true);
        $outsideConfig = "$outside/githooks.php";
        file_put_contents(
            $outsideConfig,
            $this->configurationFileBuilder->enableV3Mode()->setV3Hooks(['pre-commit' => ['qa']])->buildV3Php()
        );
        $baked = (string) realpath($outsideConfig);

        try {
            $this->artisan("hook pre-commit --config=$outsideConfig")
                ->containsStringInOutput("--config '$outsideConfig' is outside the repository: the hooks reference the absolute path '$baked' and will not work for other clones.")
                ->assertExitCode(0);

            $this->assertStringContainsString(
                " hook:run --config='$baked' \"$(basename",
                (string) file_get_contents($this->path . '/.githooks/pre-commit')
            );
        } finally {
            unlink($outsideConfig);
            rmdir($outside);
        }
    }

    /** @test BUG-31: without --config the script is the same as before — default lookup at run time. */
    function v3_mode_without_config_flag_leaves_the_script_unchanged()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa']])
            ->buildInFileSystem();

        // The parser is rooted at testsDir in tests, so the default lookup reads
        // the file built above; the installer fake writes under testsDir too.
        $this->artisan('hook pre-commit')->assertExitCode(0);

        $content = (string) file_get_contents($this->path . '/.githooks/pre-commit');
        $this->assertStringNotContainsString('--config', $content);
        $this->assertStringContainsString(" hook:run \"$(basename \"$0\")\" \"$@\"", $content);
    }

    /**
     * @test
     * @dataProvider unusableConfigProvider
     *
     * BUG-36: an explicit --config that cannot be used is an error, not a
     * reason to install a hook pointing at it. Before, every parser failure
     * fell back to the minimal install, so a missing file produced
     * `Hook pre-commit created`, exit 0, and a script calling hook:run with
     * a path that does not exist. Nothing is written in .githooks/ nor in
     * .git/hooks/.
     */
    function an_unusable_explicit_config_installs_nothing(string $fileName, ?string $content, string $expectedMessage)
    {
        $configPath = getcwd() . '/' . self::TESTS_PATH . '/' . $fileName;
        if ($content !== null) {
            file_put_contents($configPath, $content);
        }

        $this->artisan("hook pre-commit --config=$configPath")
            ->containsStringInOutput(str_replace('<path>', $configPath, $expectedMessage))
            ->notContainsStringInOutput('created')
            ->notContainsStringInOutput('installed')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . '/.git/hooks/pre-commit');
        $this->assertDirectoryDoesNotExist($this->path . '/.githooks');
    }

    public function unusableConfigProvider(): array
    {
        return [
            'missing file' => ['does-not-exist.php', null, 'Configuration file not found: <path>'],
            'v2 shape' => [
                'githooks.php',
                "<?php return ['Options' => ['execution' => 'full'], 'Tools' => ['phpstan']];",
                'v2 configuration format (Options/Tools) is not supported since 4.0: <path>',
            ],
            'yaml' => ['githooks.yml', "Tools:\n  - phpstan\n", 'YAML configuration files are not supported since 4.0: <path>'],
            'not an array' => ['githooks.php', "<?php return 'nope';", 'PHP configuration file does not return an array.'],
        ];
    }

    /**
     * @test
     * @dataProvider validationOutcomeProvider
     *
     * BUG-37: the parser's verdict decides the install the way it decides a
     * run. Errors refuse it (exit 1, nothing written) and warnings are printed
     * before the install line, as conf:check and hook:run report them. Before,
     * both went unreported and the scripts were written; the failure surfaced
     * on the first commit. Rows 3–6 of factors-hook-install.md.
     *
     * @param string[] $expected
     * @param string[] $unexpected
     */
    function the_validation_outcome_decides_the_install(
        string $config,
        array $expected,
        array $unexpected,
        int $exitCode,
        bool $installs
    ) {
        $configPath = getcwd() . '/' . self::TESTS_PATH . '/alfil.php';
        file_put_contents($configPath, $config);

        $command = $this->artisan("hook pre-commit --config=$configPath");
        foreach ($expected as $text) {
            $command->containsStringInOutput($text);
        }
        foreach ($unexpected as $text) {
            $command->notContainsStringInOutput($text);
        }
        $command->assertExitCode($exitCode);
        // The pending command runs on destruction; the files are checked after it ran.
        $command->run();

        $this->assertFileDoesNotExist($this->path . '/.git/hooks/pre-commit');
        if ($installs) {
            $this->assertFileExists($this->path . '/.githooks/pre-commit');
        } else {
            $this->assertDirectoryDoesNotExist($this->path . '/.githooks');
        }
    }

    public function validationOutcomeProvider(): array
    {
        $php = static function (array $config): string {
            return '<?php return ' . var_export($config, true) . ';';
        };
        $flows = ['qa' => ['jobs' => ['lint']]];
        $jobs = ['lint' => ['type' => 'custom', 'script' => 'true']];
        $badJobs = ['lint' => ['type' => 'nonexistent']];
        $oldCommand = ['command' => 'php vendor/bin/githooks', 'pre-commit' => ['qa']];
        $error = "❌ Job 'lint': type 'nonexistent' is not a supported tool.";
        $warning = "⚠️ 'hooks.command' calls the githooks binary, removed in 4.0: point it at alfil "
            . "(e.g. 'php vendor/bin/alfil') and run 'alfil hook'.";

        return [
            'errors: refused, nothing written' => [
                $php(['hooks' => ['pre-commit' => ['qa']], 'flows' => $flows, 'jobs' => $badJobs]),
                [$error], ['installed', 'created', '⚠️'], 1, false,
            ],
            'errors and warnings: only the errors' => [
                $php(['hooks' => $oldCommand, 'flows' => $flows, 'jobs' => $badJobs]),
                [$error], ['installed', 'created', '⚠️'], 1, false,
            ],
            'warnings, events declared: reported, then installed' => [
                $php(['hooks' => $oldCommand, 'flows' => $flows, 'jobs' => $jobs]),
                [$warning, 'Hook pre-commit installed'], ['❌'], 0, true,
            ],
            'warnings, no events: reported, nothing to install' => [
                $php(['hooks' => ['command' => 'php vendor/bin/githooks'], 'flows' => $flows, 'jobs' => $jobs]),
                [$warning, 'No hooks defined in configuration'], ['installed', '❌'], 0, false,
            ],
        ];
    }

    /**
     * @test
     *
     * Without --config and without a file in the default locations there is
     * nothing to read in v3 terms, so the minimal install (one script in
     * .git/hooks/) still has a point. The script drives hook:run, which is
     * what will resolve once the user writes a v3 file.
     */
    function no_configuration_anywhere_installs_the_minimal_hook()
    {
        $this->artisan('hook pre-commit')
            ->containsStringInOutput('Hook pre-commit created')
            ->assertExitCode(0);

        $content = (string) file_get_contents($this->path . '/.git/hooks/pre-commit');
        $this->assertStringContainsString('hook:run', $content);
        $this->assertStringNotContainsString('--config', $content);
    }

    /** @test */
    function v3_mode_warns_when_hooks_section_is_empty()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("hook pre-commit --config=$configPath")
            ->containsStringInOutput('No hooks defined in configuration')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->path . '/.git/hooks/pre-commit');
    }

    /** @test */
    function it_shows_an_error_message_when_is_setted_an_invalid_hook()
    {
        $noSupportedHook = 'no-valid';

        $supportedHooks = [
            'applypatch-msg',
            'pre-applypatch',
            'post-applypatch',
            'pre-commit',
            'pre-merge-commit',
            'prepare-commit-msg',
            'commit-msg',
            'post-commit',
            'pre-rebase',
            'post-checkout',
            'post-merge',
            'pre-push',
            'pre-receive',
            'update',
            'proc-receive',
            'post-receive',
            'post-update',
            'reference-transaction',
            'push-to-checkout',
            'pre-auto-gc',
            'post-rewrite',
            'sendemail-validate',
            'fsmonitor-watchman',
            'p4-changelist',
            'p4-prepare-changelist',
            'p4-post-changelist',
            'p4-pre-submit',
            'post-index-change',
        ];

        $supportedHooks2String = implode(', ', $supportedHooks);
        $this->artisan("hook $noSupportedHook --legacy")
            ->containsStringInOutput("'$noSupportedHook' is not a valid git hook. Available hooks are:")
            ->containsStringInOutput($supportedHooks2String)
            ->assertExitCode(1);

        $supportedHooksInOutput = explode(',', $this->getActualOutput());

        //Verifies that neither hook has been forgotten
        $this->assertCount(count($supportedHooks), $supportedHooksInOutput);
    }
}
