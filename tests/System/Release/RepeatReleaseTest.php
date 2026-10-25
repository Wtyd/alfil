<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * FEAT-25 — `flow --repeat` driven through the embedded .phar. Fails against a
 * binary without the feature: `--repeat` is an unknown option there (exit 1,
 * no flap report on stdout).
 *
 * @group release
 */
class RepeatReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = self::TESTS_PATH;
        $writer = "$dir/writer.php";
        // Pass k writes the outcome of `T::t` from the script: g passed, n failed, ! nothing, c a corrupt file.
        file_put_contents($writer, <<<'PHP'
<?php
[, $script, $counter, $out] = $argv;
$pass = is_file($counter) ? (int) file_get_contents($counter) : 0;
file_put_contents($counter, (string) ($pass + 1));
$token = explode(',', $script)[$pass] ?? '!';
if ($token === '!') {
    exit(2);
}
if ($token === 'c') {
    file_put_contents($out, '<testsuites><broken');
    exit(0);
}
$child = $token === 'n' ? '<failure>boom</failure>' : '';
file_put_contents($out, '<?xml version="1.0"?><testsuites><testsuite name="s">'
    . "<testcase classname=\"T\" name=\"t\">$child</testcase></testsuite></testsuites>");
exit($token === 'n' ? 1 : 0);
PHP);

        $this->configPath = "$dir/githooks.php";
        $this->writeConfig('g,n,g,n');
    }

    private function writeConfig(string $script, bool $twinJob = false): void
    {
        $dir = self::TESTS_PATH;
        $suite = [
            'type'   => 'custom',
            'script' => PHP_BINARY . " $dir/writer.php $script $dir/counter $dir/junit.xml",
            'junit'  => "$dir/junit.xml",
        ];
        $config = [
            'flows' => ['tests' => ['jobs' => $twinJob ? ['suite', 'twin'] : ['suite']]],
            'jobs'  => $twinJob ? ['suite' => $suite, 'twin' => $suite] : ['suite' => $suite],
        ];
        file_put_contents($this->configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /** @test */
    public function the_phar_reports_a_test_that_flips_between_passes_as_flaky(): void
    {
        passthru("$this->alfil flow tests --repeat=4 --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded, 'stdout must be the flap report and nothing else');
        $this->assertSame(1, $exitCode);
        $this->assertSame(
            [['job' => 'suite', 'test' => 'T::t', 'class' => 'flaky', 'passed' => 2, 'failed' => 2, 'skipped' => 0, 'absent' => 0]],
            $decoded['tests']
        );
    }

    /** @test */
    public function the_phar_never_reads_a_previous_pass_as_the_result_of_a_crashed_one(): void
    {
        $this->writeConfig('g,!');

        passthru("$this->alfil flow tests --repeat=2 --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(0, $exitCode);
        $this->assertSame([1, [['pass' => 2, 'job' => 'suite', 'reason' => 'missing']]], [
            $decoded['tests'][0]['passed'],
            $decoded['anomalies'],
        ]);
    }


    /**
     * @test
     * @dataProvider flapThresholds
     *
     * The threshold is a count over the rarer outcome: with 2 passes and 2
     * failures the test is flaky at `--flap-threshold=2` and only suspect
     * (listed, exit 0) one step above.
     */
    public function the_flap_threshold_is_a_count_over_the_rarer_outcome(int $threshold, string $class, int $exit): void
    {
        passthru("$this->alfil flow tests --repeat=4 --flap-threshold=$threshold --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame([$exit, $class, $exit === 0], [$exitCode, $decoded['tests'][0]['class'], $decoded['success']]);
    }

    /** @return array<string, array{int, string, int}> */
    public function flapThresholds(): array
    {
        return [
            'rarer outcome == threshold (flaky, fails)'  => [2, 'flaky', 1],
            'rarer outcome < threshold (suspect, passes)' => [3, 'suspect', 0],
        ];
    }

    /** @test Two jobs writing the same junit file cannot be told apart: refused before the first pass. */
    public function two_jobs_writing_the_same_junit_file_are_refused(): void
    {
        $this->writeConfig('g,n', true);

        $output = [];
        exec("$this->alfil flow tests --repeat=2 --config=$this->configPath 2>&1", $output, $exitCode);

        $dir = self::TESTS_PATH;
        $this->assertSame(1, $exitCode);
        $this->assertSame(
            "Jobs 'suite' and 'twin' both write their junit file to '$dir/junit.xml'; --repeat cannot tell their tests apart. Give each job its own 'junit' path.",
            trim(implode("\n", $output))
        );
        $this->assertFileDoesNotExist("$dir/counter", 'no pass may run');
    }

    /**
     * @test
     * @dataProvider singleRunFormats
     */
    public function single_run_formats_are_refused_with_repeat(string $format): void
    {
        $output = [];
        exec("$this->alfil flow tests --repeat=2 --format=$format --config=$this->configPath 2>&1", $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertSame(
            "--format=$format describes a single run and cannot be used with --repeat. Use text or json.",
            trim(implode("\n", $output))
        );
    }

    /** @return array<string, array{string}> */
    public function singleRunFormats(): array
    {
        return ['junit' => ['junit'], 'sarif' => ['sarif'], 'codeclimate' => ['codeclimate']];
    }

    /** @test Flags that describe one run are ignored, and stderr names them. */
    public function single_run_flags_are_ignored_with_a_warning(): void
    {
        $stderr = self::TESTS_PATH . '/stderr.txt';

        exec("$this->alfil flow tests --repeat=2 --save-history --monitor --format=json --config=$this->configPath 2>$stderr", $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertSame('Warning: --save-history, --monitor ignored with --repeat.', trim((string) file_get_contents($stderr)));
    }

    /** @test A junit file the pass wrote but cannot be parsed is an anomaly, not a result. */
    public function a_corrupt_junit_file_is_reported_as_an_anomaly(): void
    {
        $this->writeConfig('g,c,g');

        passthru("$this->alfil flow tests --repeat=3 --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(0, $exitCode);
        $this->assertSame([2, [['pass' => 2, 'job' => 'suite', 'reason' => 'corrupt']]], [
            $decoded['tests'][0]['passed'],
            $decoded['anomalies'],
        ]);
    }

    /** @test --dry-run shows the plan once and repeats nothing. */
    public function dry_run_shows_the_plan_once_and_runs_no_pass(): void
    {
        passthru("$this->alfil flow tests --repeat=3 --dry-run --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertSame(0, $exitCode);
        $this->assertSame(true, $decoded['dryRun']);
        $this->assertFileDoesNotExist(self::TESTS_PATH . '/counter', 'the script must not have run');
    }
}
