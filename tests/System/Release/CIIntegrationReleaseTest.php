<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the CI integration layer (3.2 + 3.4):
 *  - GitHub Actions / GitLab CI auto-detection via env vars.
 *  - `::group::` / `::endgroup::` and `::error file=…,line=…::` annotations
 *    parsed from tool output.
 *  - `--no-ci` opt-out.
 *  - HumanIssueFormatter integration with the CI decorator (3.4 fix): when
 *    multi-report turns `structuredFormat` on, the JSON crudo emitted by
 *    tools must be humanised before reaching the per-job error section.
 *
 * @group release
 */
class CIIntegrationReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /** @test */
    public function github_actions_annotations_are_emitted_when_env_var_is_set(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_with_file_location']]])
            ->setV3Jobs([
                'fail_with_file_location' => [
                    'type'   => 'custom',
                    'script' => 'echo "src/Broken.php:42: Unexpected error" && exit 1',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru(
            "GITHUB_ACTIONS=true $this->alfil flow qa --config=$this->configPath 2>&1",
            $exitCode
        );

        $output = $this->getActualOutput();
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('::group::fail_with_file_location', $output);
        $this->assertStringContainsString('::error file=src/Broken.php,line=42::', $output);
        $this->assertStringContainsString('::endgroup::', $output);
    }

    /** @test */
    public function ci_annotations_are_suppressed_when_no_ci_flag_is_passed(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_with_file_location']]])
            ->setV3Jobs([
                'fail_with_file_location' => [
                    'type'   => 'custom',
                    'script' => 'echo "src/Broken.php:42: Unexpected error" && exit 1',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru(
            "GITHUB_ACTIONS=true $this->alfil flow qa --no-ci --config=$this->configPath 2>&1",
            $exitCode
        );

        $output = $this->getActualOutput();
        $this->assertStringNotContainsString('::group::', $output);
        $this->assertStringNotContainsString('::error file=', $output);
    }

    /**
     * 3.4 Fixed — when a structured format is requested via the multi-report
     * route (`--report-codeclimate=PATH` while keeping text on stdout), the
     * tools emit JSON for the file-based formatter and `FlowExecutor::buildResult`
     * must humanise that JSON via `HumanIssueFormatter` before forwarding to
     * `onJobError`. The GitHub Actions decorator (`GITHUB_ACTIONS=true`)
     * receives that humanised body and parses it for `::error file=…,line=…::`
     * annotations.
     *
     * The decisive marker is the literal `[SyntaxError]` tag — that string is
     * the ruleId set by `ParallelLintOutputParser` only when the JSON has
     * been parsed by `HumanIssueFormatter`. The raw parallel-lint JSON uses
     * `"type":"syntaxError"` (camelCase), not `[SyntaxError]`.
     *
     * @test
     */
    public function phar_humanizes_parallel_lint_json_output_in_error_section(): void
    {
        $tests = self::TESTS_PATH;
        $src = "$tests/src-broken";
        @mkdir($src, 0777, true);
        file_put_contents("$src/Broken.php", "<?php\nfunction foo( {\n");

        $linter = '/var/www/html1/vendor/bin/parallel-lint';
        if (!is_file($linter)) {
            $this->markTestSkipped('parallel-lint binary not present in vendor/bin');
        }

        $reportPath = self::TESTS_PATH . '/humanise-cc.json';
        @unlink($reportPath);

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['lint_src']]])
            ->setV3Jobs([
                'lint_src' => [
                    'type'            => 'parallel-lint',
                    'executable-path' => $linter,
                    'paths'           => [$src],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = sprintf(
            'GITHUB_ACTIONS=true %s flow qa --report-codeclimate=%s --config=%s 2>&1',
            $this->alfil,
            $reportPath,
            $this->configPath
        );
        passthru($cmd, $exitCode);
        $output = $this->getActualOutput();

        @unlink($reportPath);

        $this->assertNotSame(0, $exitCode, 'lint_src must fail on the syntax error');
        $this->assertStringContainsString('::error file=', $output, 'GHA annotation must be emitted');
        $this->assertStringContainsString(
            '[SyntaxError]',
            $output,
            'HumanIssueFormatter must render the parser ruleId in the GHA annotation body'
        );
        $this->assertMatchesRegularExpression(
            '/::error file=[^,]+,line=\d+::.*line \d+/',
            $output,
            'GHA annotation body must include the humanised `line N` marker'
        );
    }


    /**
     * 4.0 — GitLab CI collapsible sections are named after alfil
     * (`alfil_job_N`, `alfil_summary`). `GITHUB_ACTIONS` is unset for the
     * run because the detector checks it first, and the release pipeline
     * itself runs on GitHub Actions.
     *
     * @test
     */
    public function gitlab_ci_sections_are_named_after_alfil(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job', 'ko_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
                'ko_job' => ['type' => 'custom', 'script' => 'echo "src/Broken.php:42: Unexpected error" && exit 1'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("env -u GITHUB_ACTIONS GITLAB_CI=true $this->alfil flow qa --config=$this->configPath 2>&1", $exitCode);

        preg_match_all('/section_(?:start|end):\d+:([a-z_0-9]+)/', $this->getActualOutput(), $matches);
        $sections = array_values(array_unique($matches[1]));
        sort($sections);
        $this->assertSame(1, $exitCode);
        $this->assertSame(['alfil_job_1', 'alfil_job_2', 'alfil_summary'], $sections);
    }
}
