<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use Wtyd\Alfil\Utils\Printer;

/**
 * The Printer colours only where the colours render: when told to, on a CI
 * runner (whose logs render ANSI, as FlowResultRenderer assumes when it forces
 * the Symfony output there) or when stdout is a terminal. Through a pipe the
 * text is plain.
 *
 * Decision table over (explicit flag, CI, stdout is a TTY):
 *
 * | flag  | CI  | TTY | colours |
 * |-------|-----|-----|---------|
 * | true  | *   | *   | yes     |
 * | false | *   | *   | no      |
 * | null  | yes | *   | yes     |
 * | null  | no  | yes | yes     |
 * | null  | no  | no  | no      |
 *
 * The two TTY rows cannot be pinned under PHPUnit, whose stdout may or may not
 * be a terminal; the pipe row is covered on the real binary by
 * OutputFormatsReleaseTest::text_output_through_a_pipe_carries_no_ansi_escapes.
 */
class PrinterTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['GITHUB_ACTIONS', 'GITLAB_CI'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    private function capture(callable $print): string
    {
        ob_start();
        $print();

        return (string) ob_get_clean();
    }

    /**
     * @test
     * @dataProvider lines
     *
     * @param mixed[] $args
     */
    public function a_decorated_printer_colours_the_message_and_leaves_the_markers_outside(
        string $method,
        array $args,
        string $coloured
    ): void {
        $printer = new Printer(true);

        $this->assertSame($coloured, $this->capture(function () use ($printer, $method, $args): void {
            $printer->$method(...$args);
        }));
    }

    /**
     * @test
     * @dataProvider lines
     *
     * @param mixed[] $args
     */
    public function a_plain_printer_writes_the_same_text_without_escapes(
        string $method,
        array $args,
        string $coloured,
        string $plain
    ): void {
        $printer = new Printer(false);

        $this->assertSame($plain, $this->capture(function () use ($printer, $method, $args): void {
            $printer->$method(...$args);
        }));
    }

    /** @return array<string, array{string, mixed[], string, string}> */
    public function lines(): array
    {
        return [
            'success: green block'      => ['success', ['done'], "\e[42m\e[30mdone\e[0m\n", "done\n"],
            'info is success'           => ['info', ['done'], "\e[42m\e[30mdone\e[0m\n", "done\n"],
            'error: red block'          => ['error', ['bad'], "\e[41m\e[30mbad\e[0m\n", "bad\n"],
            'warning: yellow block'     => ['warning', ['hmm'], "\e[43m\e[30mhmm\e[0m\n", "hmm\n"],
            'comment is warning'        => ['comment', ['hmm'], "\e[43m\e[30mhmm\e[0m\n", "hmm\n"],
            'resultError: marker outside the block' => [
                'resultError', ['bad'], "❌ \e[41m\e[30mbad\e[0m\n", "❌ bad\n",
            ],
            'resultWarning: marker outside the block' => [
                'resultWarning', ['hmm'], "⚠️ \e[43m\e[30mhmm\e[0m\n", "⚠️ hmm\n",
            ],
            'jobSuccess: indentation outside, padding inside' => [
                'jobSuccess', ['lint', '1s'], "  \e[42m\e[30m lint - OK. Time: 1s \e[0m\n", "   lint - OK. Time: 1s \n",
            ],
            'jobError: indentation outside, padding inside' => [
                'jobError', ['lint', '1s'], "  \e[41m\e[30m lint - KO. Time: 1s \e[0m\n", "   lint - KO. Time: 1s \n",
            ],
            'line: never coloured'      => ['line', ['raw'], "raw\n", "raw\n"],
        ];
    }

    /** @test */
    public function paint_wraps_the_text_in_the_given_codes_only_when_decorated(): void
    {
        $this->assertSame("\e[36mlint\e[0m", (new Printer(true))->paint('lint', '36'));
        $this->assertSame("\e[42m\e[30mlint\e[0m", (new Printer(true))->paint('lint', '42', '30'));
        $this->assertSame('lint', (new Printer(false))->paint('lint', '36'));
    }

    /**
     * @test
     * @dataProvider ciEnvironments
     *
     * Rows 3 of the table: on a CI runner the colours render in the logs, so
     * the auto-detected printer colours whatever stdout is.
     */
    public function on_a_ci_runner_the_default_printer_colours(string $variable, string $value): void
    {
        putenv("$variable=$value");

        $this->assertSame("\e[41m\e[30mbad\e[0m", (new Printer())->paint('bad', '41', '30'));
    }

    /** @return array<string, array{string, string}> */
    public function ciEnvironments(): array
    {
        return [
            'GitHub Actions' => ['GITHUB_ACTIONS', 'true'],
            'GitLab CI'      => ['GITLAB_CI', 'true'],
        ];
    }
}
