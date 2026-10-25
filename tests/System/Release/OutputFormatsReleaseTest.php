<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the structured output formats added in 3.2:
 * JSON v2, JUnit, Code Climate, SARIF, plus the `--output=PATH` target
 * and the per-payload contracts (relative paths, executionMode, pretty
 * printing). Progress emission and dashboard fallback live in
 * {@see ProgressOutputReleaseTest}.
 *
 * @group release
 */
class OutputFormatsReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /**
     * 3.7 — an unknown `--format` is an advisory, not payload. It was written to
     * stdout, so it landed in the middle of whatever the caller was capturing;
     * the diagnostic commands already routed it to stderr.
     *
     * @test
     */
    public function unknown_format_warning_goes_to_stderr_and_leaves_stdout_clean(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => 'true']]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stdoutFile = tempnam(sys_get_temp_dir(), 'gh-out');
        $stderrFile = tempnam(sys_get_temp_dir(), 'gh-err');

        exec("$this->alfil flow qa --format=csv --config=$this->configPath > $stdoutFile 2> $stderrFile", $ignored, $exitCode);

        $stdout = (string) file_get_contents($stdoutFile);
        $stderr = (string) file_get_contents($stderrFile);
        @unlink($stdoutFile);
        @unlink($stderrFile);

        $this->assertSame(0, $exitCode, 'An unknown format falls back to text; the exit code is unaffected');
        $this->assertStringContainsString("Unknown format 'csv'", $stderr);
        $this->assertStringNotContainsString('Unknown format', $stdout);
        $this->assertStringContainsString('Results:', $stdout, 'stdout still carries the text report');
    }

    /** @test */
    public function json_v2_schema_includes_enriched_fields_per_job(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true', 'paths' => ['src']],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(2, $decoded['version']);
        $this->assertArrayHasKey('executionMode', $decoded);
        $this->assertArrayHasKey('passed', $decoded);
        $this->assertArrayHasKey('failed', $decoded);
        $this->assertArrayHasKey('skipped', $decoded);

        $job = $decoded['jobs'][0];
        $this->assertSame('ok_job', $job['name']);
        $this->assertSame('custom', $job['type']);
        $this->assertSame(0, $job['exitCode']);
        $this->assertSame(['src'], $job['paths']);
        $this->assertFalse($job['skipped']);
        $this->assertNull($job['skipReason']);
        $this->assertFalse($job['fixApplied']);
    }

    /**
     * FEAT-15 — `--format=claude-code` emits the Claude Code stop-hook protocol
     * straight from the embedded .phar: a failing flow must print a parseable
     * `{"decision":"block","reason":…}` to stdout AND exit 0 (the protocol only
     * honours the JSON on a zero exit). This guards the contract end-to-end so a
     * regression in the build/embed would fail here.
     *
     * @test
     */
    public function claude_code_format_blocks_with_exit_0_on_failure(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_job']]])
            ->setV3Jobs([
                'fail_job' => ['type' => 'custom', 'script' => 'echo "boom" && exit 1'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --format=claude-code --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode, 'claude-code must exit 0 even when a job fails');

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded, 'stdout must be a single parseable JSON object');
        $this->assertSame('block', $decoded['decision']);
        $this->assertNotEmpty($decoded['reason']);
        $this->assertStringContainsString('fail_job', $decoded['reason']);
    }

    /**
     * FEAT-15 — a passing flow must produce no stdout (and exit 0) so the stop
     * hook stays silent and the agent is not blocked.
     *
     * @test
     */
    public function claude_code_format_is_silent_on_success(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --format=claude-code --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', trim($this->getActualOutput()), 'stdout must be empty on success');
    }

    /** @test */
    public function codeclimate_format_emits_valid_json_array_to_stdout(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --format=codeclimate --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded, 'Code Climate output must be a JSON array');
    }

    /** @test */
    public function sarif_format_emits_valid_2_1_0_report_to_stdout(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --format=sarif --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.1.0', $decoded['version'] ?? null);
        $this->assertArrayHasKey('runs', $decoded);
        $this->assertArrayHasKey('$schema', $decoded);
    }

    /**
     * FEAT-13 (3.4) — when a job declares `execution: fast-dirty` and is
     * invoked via `githooks job X` with no CLI mode flag, the JSON envelope
     * must report `executionMode: "fast-dirty"` and
     * `effectiveOptions.executionMode.source: "jobs.X.execution"`. The actual
     * file-set filtering always honoured the job-declared mode
     * (FlowPreparer::resolveMode), but pre-3.4.1 the envelope reported
     * `"full"/"default"` — consumers (CI, AI tools) saw the wrong mode.
     *
     * @test
     */
    public function json_envelope_reflects_job_level_execution_when_job_command_is_used(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['lint_dirty']]])
            ->setV3Jobs([
                'lint_dirty' => [
                    'type'      => 'custom',
                    'script'    => 'true',
                    'execution' => 'fast-dirty',
                    'paths'     => ['.'],
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru(
            "$this->alfil job lint_dirty --format=json --config=$this->configPath 2>/dev/null",
            $exitCode
        );

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            'fast-dirty',
            $decoded['executionMode'],
            'top-level executionMode must reflect jobs.X.execution'
        );
        $this->assertSame(
            'fast-dirty',
            $decoded['effectiveOptions']['executionMode']['value']
        );
        $this->assertSame(
            'jobs.lint_dirty.execution',
            $decoded['effectiveOptions']['executionMode']['source']
        );
    }

    /** @test */
    public function json_executionMode_reflects_the_cli_flag(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = "$this->alfil flow qa --format=json --config=$this->configPath 2>/dev/null";

        $full = json_decode((string) shell_exec($cmd), true);
        $fast = json_decode((string) shell_exec(str_replace('--format', '--fast --format', $cmd)), true);
        $branch = json_decode((string) shell_exec(str_replace('--format', '--fast-branch --format', $cmd)), true);

        $this->assertSame('full', $full['executionMode']);
        $this->assertSame('fast', $fast['executionMode']);
        $this->assertSame('fast-branch', $branch['executionMode']);
    }

    /** @test */
    public function codeclimate_location_path_is_relative_to_cwd(): void
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        file_put_contents(
            "$srcDir/Bad.php",
            "<?php\nclass Bad { public \$x; }\n"
        );

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_job']]])
            ->setV3Jobs([
                'phpcs_job' => [
                    'type'     => 'phpcs',
                    'standard' => 'PSR12',
                    'paths'    => [$srcDir],
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --format=codeclimate --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded, 'phpcs should have emitted at least one issue');

        foreach ($decoded as $issue) {
            $path = $issue['location']['path'];
            $this->assertStringStartsNotWith('/', $path, "location.path must be relative, got: $path");
        }
    }

    /** @test */
    public function sarif_artifactLocation_uri_is_relative_to_cwd(): void
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        file_put_contents(
            "$srcDir/Bad.php",
            "<?php\nclass Bad { public \$x; }\n"
        );

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_job']]])
            ->setV3Jobs([
                'phpcs_job' => [
                    'type'     => 'phpcs',
                    'standard' => 'PSR12',
                    'paths'    => [$srcDir],
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --format=sarif --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $sawIssue = false;
        foreach ($decoded['runs'] as $run) {
            foreach ($run['results'] ?? [] as $result) {
                foreach ($result['locations'] ?? [] as $location) {
                    $uri = $location['physicalLocation']['artifactLocation']['uri'] ?? '';
                    $this->assertStringStartsNotWith('/', $uri, "artifactLocation.uri must be relative, got: $uri");
                    $sawIssue = true;
                }
            }
        }
        $this->assertTrue($sawIssue, 'SARIF report should contain at least one result with a location');
    }

    /** @test */
    public function output_flag_writes_payload_to_file_for_each_structured_format(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $targets = [
            'json'        => self::TESTS_PATH . '/qa.json',
            'junit'       => self::TESTS_PATH . '/qa.xml',
            'codeclimate' => self::TESTS_PATH . '/qa-cc.json',
            'sarif'       => self::TESTS_PATH . '/qa.sarif',
        ];

        foreach ($targets as $format => $path) {
            @unlink($path);
            shell_exec("$this->alfil flow qa --format=$format --output=$path --config=$this->configPath 2>/dev/null");
            $this->assertFileExists($path, "--output=PATH should create the file for format=$format");
            $this->assertNotSame('', (string) file_get_contents($path));
        }
    }

    /** @test */
    public function junit_skipped_element_is_emitted_for_skipped_jobs(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_job', 'never_job']]])
            ->setV3Jobs([
                'fail_job'  => ['type' => 'custom', 'script' => 'exit 1'],
                'never_job' => ['type' => 'custom', 'script' => 'echo never'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $output = (string) shell_exec(
            "$this->alfil flow qa --fail-fast --format=junit --config=$this->configPath 2>/dev/null"
        );

        $this->assertStringContainsString('<?xml', $output);
        $this->assertStringContainsString('<skipped', $output);
        $this->assertStringContainsString('never_job', $output);
    }

    /** @test */
    public function fail_fast_cancelled_jobs_appear_as_skipped_in_json(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_job', 'never_job', 'also_never_job']]])
            ->setV3Jobs([
                'fail_job'        => ['type' => 'custom', 'script' => 'exit 1'],
                'never_job'       => ['type' => 'custom', 'script' => 'echo never'],
                'also_never_job'  => ['type' => 'custom', 'script' => 'echo also'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --fail-fast --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $names = array_column($decoded['jobs'], 'name');
        $this->assertSame(['fail_job', 'never_job', 'also_never_job'], $names, 'jobs[] must contain the full plan');

        $skipped = array_values(array_filter($decoded['jobs'], fn($j) => $j['skipped'] === true));
        $this->assertCount(2, $skipped);
        foreach ($skipped as $job) {
            $this->assertSame('skipped by fail-fast', $job['skipReason']);
        }

        $this->assertSame(2, $decoded['skipped']);
    }

    /** @test */
    public function junit_failure_pretty_prints_phpstan_compact_json_output(): void
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        $phpstanFile = $this->phpFileBuilder->buildWithErrors([\Tests\Utils\PhpFileBuilder::PHPSTAN]);
        file_put_contents("$srcDir/Bad.php", $phpstanFile);

        $junitPath = self::TESTS_PATH . '/qa.junit.xml';
        $codeclimatePath = self::TESTS_PATH . '/qa.cc.json';
        @unlink($junitPath);
        @unlink($codeclimatePath);

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'phpstan', 'level' => 0, 'paths' => [$srcDir]],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // structuredFormat must be active so phpstan emits JSON. Triggered via
        // --report-codeclimate (typical GitLab pipeline shape: JUnit + CC
        // generated together).
        shell_exec(
            "$this->alfil flow qa --format=junit --output=$junitPath"
            . " --report-codeclimate=$codeclimatePath --config=$this->configPath 2>/dev/null"
        );

        $this->assertFileExists($junitPath);
        $dom = new \DOMDocument();
        $this->assertTrue($dom->load($junitPath), 'junit output must be valid XML');

        $failures = $dom->getElementsByTagName('failure');
        $this->assertGreaterThan(0, $failures->length, 'phpstan errors must produce a <failure> element');

        $failureText = $failures->item(0)->textContent;
        $bodyNewlines = substr_count(trim($failureText), "\n");
        $this->assertGreaterThan(
            5,
            $bodyNewlines,
            'phpstan JSON inside <failure> must be pretty-printed (>5 newlines); got ' . $bodyNewlines
        );

        $jsonEnd = strrpos($failureText, '}');
        $jsonOnly = substr($failureText, 0, $jsonEnd + 1);
        $decoded = json_decode(trim($jsonOnly), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('files', $decoded);

        @unlink($junitPath);
        @unlink($codeclimatePath);
    }


    /**
     * 4.0 — the SARIF driver is named `alfil`: GitHub Code Scanning keys its
     * alerts by tool name, so the rename closes and reopens them once. A clean
     * run is the one run the binary itself signs (a tool with issues gets a
     * run of its own, named after the tool).
     *
     * @test
     */
    public function sarif_names_alfil_as_the_driver_of_a_clean_run(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->alfil flow qa --format=sarif --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertSame(0, $exitCode);
        $this->assertSame(['alfil'], array_column(array_column(array_column($decoded['runs'], 'tool'), 'driver'), 'name'));
    }
}
