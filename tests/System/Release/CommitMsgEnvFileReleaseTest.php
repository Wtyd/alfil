<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * 4.0: `ALFIL_COMMIT_MSG_FILE` against the compiled binary. A commit-msg job
 * run through `flow` has no hook argument and no --message-file, so the
 * variable is its only way to the message file; a binary that does not read
 * it lands on this repository's .git/COMMIT_EDITMSG (the last commit message,
 * a valid one) or on nothing at all. The env file always carries an invalid
 * subject, so a failing verdict can only have come through the variable.
 *
 * @group release
 */
class CommitMsgEnvFileReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    private string $badFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/alfil.php';
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['commit-msg' => ['commit-format']])
            ->addV3Flow('commit-checks', ['commit-format'])
            ->addV3Job('commit-format', 'commit-msg', ['preset' => 'conventional-commits']);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $this->badFile = self::TESTS_PATH . '/commit-msg-bad.txt';
        file_put_contents($this->badFile, "Add stuff.\n");
    }

    /** Run the binary with the variable pointing at the invalid file; returns [exit code, stdout]. */
    private function runWithEnvFile(string $arguments): array
    {
        $output = [];
        exec(
            'ALFIL_COMMIT_MSG_FILE=' . escapeshellarg($this->badFile)
                . " $this->alfil $arguments --config=$this->configPath 2>/dev/null",
            $output,
            $exitCode
        );

        return [$exitCode, implode("\n", $output)];
    }

    /** @test A flow hands the commit-msg job the file named by ALFIL_COMMIT_MSG_FILE. */
    public function flow_reads_the_message_file_from_the_environment()
    {
        [$exitCode, $stdout] = $this->runWithEnvFile('flow commit-checks --format=json');
        $payload = json_decode($stdout, true);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('Subject:   Add stuff.', $payload['jobs'][0]['output']);
    }

    /** @test The hook argument keeps its priority over the variable. */
    public function the_hook_argument_wins_over_the_environment()
    {
        $goodFile = self::TESTS_PATH . '/commit-msg-ok.txt';
        file_put_contents($goodFile, "feat(api): add user endpoint\n");

        [$exitCode, $stdout] = $this->runWithEnvFile("hook:run commit-msg $goodFile");

        $this->assertSame(0, $exitCode, $stdout);
    }


    /**
     * @test
     *
     * The 3.x name is not read (it never was). From testsDir there is no
     * `.git/COMMIT_EDITMSG` to fall back to, so a binary that read the old
     * variable would validate the file; this one reports that no source is
     * available, naming the variable that would have worked.
     */
    public function the_old_variable_name_is_not_read()
    {
        $output = [];
        exec(
            'cd ' . escapeshellarg(self::TESTS_PATH) . ' && GITHOOKS_COMMIT_MSG_FILE='
                . escapeshellarg(realpath($this->badFile)) . ' ./alfil flow commit-checks --format=json --config=alfil.php 2>/dev/null',
            $output,
            $exitCode
        );
        $payload = json_decode(implode("\n", $output), true);

        $this->assertSame(1, $exitCode, implode("\n", $output));
        $this->assertSame(
            "commit-msg: no message file available. Provide --message-file, --message, "
                . "set ALFIL_COMMIT_MSG_FILE, or run via the 'commit-msg' git hook.",
            $payload['jobs'][0]['output']
        );
    }
}
