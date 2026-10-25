<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * 4.0 removed the v2 engine. Against the compiled binary: a configuration 4.0
 * does not read (`Options`/`Tools`, a `.yml`, a PHP file that is not an array,
 * camelCase job keys) is rejected with one line, the same in every command,
 * and `hook` installs nothing. Decision table in factors-v2-removal.md.
 *
 * @group release
 */
class V2RemovalReleaseTest extends ReleaseTestCase
{
    private const V2 = "<?php return ['Options' => ['execution' => 'full'], 'Tools' => ['phpstan'], "
        . "'phpstan' => ['executablePath' => 'vendor/bin/phpstan', 'paths' => ['src']]];";

    private const NOT_ARRAY = "<?php return 'not an array';";

    private const CAMEL_CASE = "<?php return ['flows' => ['qa' => ['jobs' => ['lint']]], 'jobs' => ['lint' => "
        . "['type' => 'phpstan', 'executablePath' => 'vendor/bin/phpstan', 'paths' => ['src'], "
        . "'otherArguments' => '--no-progress', 'ignoreErrorsOnExit' => true]]];";

    protected function setUp(): void
    {
        parent::setUp();

        file_put_contents(self::TESTS_PATH . '/alfil-v2.php', self::V2);
        file_put_contents(self::TESTS_PATH . '/githooks.yml', "hooks:\n  pre-commit: [qa]\n");
        file_put_contents(self::TESTS_PATH . '/alfil-not-array.php', self::NOT_ARRAY);
        file_put_contents(self::TESTS_PATH . '/alfil-camelcase.php', self::CAMEL_CASE);
    }

    /** The file 4.0 does not read, by kind, and the one line it answers with. */
    private function rejection(string $kind): array
    {
        switch ($kind) {
            case 'v2':
                $file = self::TESTS_PATH . '/alfil-v2.php';
                return [$file, 'v2 configuration format (Options/Tools) is not supported since 4.0: '
                    . realpath($file) . ". Migrate it with GitHooks 3.8 ('githooks conf:migrate') before upgrading."
                ];
            case 'yaml':
                $file = self::TESTS_PATH . '/githooks.yml';
                return [$file, 'YAML configuration files are not supported since 4.0: ' . realpath($file)
                    . ". Migrate it with GitHooks 3.8 ('githooks conf:migrate') to githooks.php before upgrading."
                ];
            default:
                return [self::TESTS_PATH . '/alfil-not-array.php', 'PHP configuration file does not return an array.'];
        }
    }

    /**
     * Run the binary with stderr merged; returns [exit code, trimmed output].
     * On a CI runner the binary colours its lines even without a TTY (the
     * logs render them), so the ANSI escapes are stripped: the contract under
     * test is the text.
     */
    private function invoke(string $arguments): array
    {
        $output = [];
        exec("$this->alfil $arguments 2>&1", $output, $exitCode);
        $text = (string) preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $output));

        return [$exitCode, trim($text)];
    }

    /**
     * @test
     * @dataProvider rejectedConfigurations
     *
     * Rows 1–8 and 10–15 of the decision table: the whole output is the one
     * line (`conf:check` prefixes its error marker), exit 1, in every command.
     */
    public function a_configuration_4_0_does_not_read_is_rejected_with_one_line(string $kind, string $command): void
    {
        [$file, $message] = $this->rejection($kind);

        [$exitCode, $output] = $this->invoke("$command --config=$file");

        $this->assertSame(1, $exitCode);
        $this->assertSame(($command === 'conf:check' ? '❌ ' : '') . $message, $output);
    }

    /** @return array<string, array{string, string}> */
    public function rejectedConfigurations(): array
    {
        $cases = [];
        foreach (['flow qa', 'flows qa', 'job ok', 'conf:check', 'hook', 'hook:run pre-commit', 'status', 'cache:clear'] as $command) {
            $cases["v2 × $command"] = ['v2', $command];
        }
        foreach (['yaml', 'not-array'] as $kind) {
            foreach (['flow qa', 'conf:check', 'hook'] as $command) {
                $cases["$kind × $command"] = [$kind, $command];
            }
        }
        return $cases;
    }

    /** @test Row 9: the JSON form carries the rejection as its only error, no v3 blocks. */
    public function conf_check_json_carries_the_rejection_as_its_only_error(): void
    {
        [$file, $message] = $this->rejection('v2');

        $output = [];
        exec("$this->alfil conf:check --format=json --config=$file 2>/dev/null", $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame(
            ['version' => 2, 'valid' => false, 'errors' => [$message], 'warnings' => [], 'deprecations' => []],
            $payload
        );
    }

    /** @test Row 16: a camelCase job key is an error that names the kebab-case key, one per key. */
    public function camel_case_job_keys_are_rejected_naming_the_kebab_case_key(): void
    {
        $file = self::TESTS_PATH . '/alfil-camelcase.php';

        $output = [];
        exec("$this->alfil conf:check --format=json --config=$file 2>/dev/null", $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame([false, [
            "Job 'lint': key 'executablePath' was removed in 4.0. Use 'executable-path'.",
            "Job 'lint': key 'otherArguments' was removed in 4.0. Use 'other-arguments'.",
            "Job 'lint': key 'ignoreErrorsOnExit' was removed in 4.0. Use 'ignore-errors-on-exit'.",
        ]
        ], [$payload['valid'], $payload['errors']]);
    }

    /**
     * @test
     *
     * Row 17 (the BUG-36 family): a rejected configuration installs nothing.
     * The binary runs against this repository's own wiring, where `.githooks/`
     * legitimately exists, so "nothing" is a snapshot of the scripts and of
     * `core.hooksPath` before and after.
     */
    public function hook_writes_nothing_for_a_configuration_4_0_does_not_read(): void
    {
        $snapshot = static function (): array {
            $state = ['core.hooksPath' => trim((string) shell_exec('git config core.hooksPath 2>/dev/null'))];
            foreach (['.githooks/pre-commit', '.git/hooks/pre-commit'] as $script) {
                $state[$script] = is_file($script) ? (string) file_get_contents($script) : null;
            }
            return $state;
        };
        $before = $snapshot();

        foreach (['v2', 'yaml', 'not-array'] as $kind) {
            [$file] = $this->rejection($kind);
            [$exitCode, $output] = $this->invoke("hook --config=$file");

            $this->assertSame(1, $exitCode, $kind);
            $this->assertStringNotContainsString('installed', $output, $kind);
            $this->assertSame($before, $snapshot(), "$kind: no hook script or hooksPath may be written or changed");
        }
    }
}
