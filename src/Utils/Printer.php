<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Utils;

use Wtyd\Alfil\Output\CI\CIEnvironment;

/**
 * Coloured lines for the text output, coloured only where the colours render:
 * on a terminal, or on a CI runner, whose logs render ANSI (the rule
 * FlowResultRenderer applies to the Symfony output). Through a pipe the text
 * is plain. Pass $decorated to settle it explicitly.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class Printer
{
    private ?bool $decorated;

    public function __construct(?bool $decorated = null)
    {
        $this->decorated = $decorated;
    }

    /** Wrap $text in the SGR $codes (`36`, `1`, `42` + `30`…) when colours render. */
    public function paint(string $text, string ...$codes): string
    {
        if (!$this->decorated()) {
            return $text;
        }

        $open = '';
        foreach ($codes as $code) {
            $open .= "\e[{$code}m";
        }

        return "{$open}{$text}\e[0m";
    }

    private function decorated(): bool
    {
        if ($this->decorated === null) {
            $this->decorated = CIEnvironment::detect() !== CIEnvironment::NONE
                || (defined('STDOUT') && stream_isatty(STDOUT));
        }

        return $this->decorated;
    }

    public function resultWarning(string $message): void
    {
        echo "⚠️ ";
        $this->warning($message);
    }

    public function resultError(string $message): void
    {
        echo "❌ ";
        $this->error($message);
    }

    // Standard print
    public function line(string $message): void
    {
        echo "$message\n";
    }

    public function emptyLine(): void
    {
        echo "\n";
    }

    /**
     * @param string $toolName
     * @param string $errorMessage
     */
    public function framedErrorBlock(string $toolName, string $errorMessage): void
    {
        $frameWidth = 80;
        $headLines = 20;
        $tailLines = 3;

        echo "  ┌" . str_repeat('─', $frameWidth - 1) . "\n";

        $lines = explode("\n", $errorMessage);
        $totalLines = count($lines);

        if ($totalLines <= $headLines + $tailLines) {
            foreach ($lines as $line) {
                echo "  │ $line\n";
            }
        } else {
            $omitted = $totalLines - $headLines - $tailLines;
            for ($i = 0; $i < $headLines; $i++) {
                echo "  │ " . $lines[$i] . "\n";
            }
            echo "  │ ... ($omitted lines omitted — run 'alfil job $toolName' for full output)\n";
            for ($i = $totalLines - $tailLines; $i < $totalLines; $i++) {
                echo "  │ " . $lines[$i] . "\n";
            }
        }

        echo "  └" . str_repeat('─', $frameWidth - 1) . "\n";
    }

    // Job status lines — indentation outside color block
    public function jobSuccess(string $jobName, string $time): void
    {
        echo '  ' . $this->paint(" $jobName - OK. Time: $time ", '42', '30') . "\n";
    }

    public function jobError(string $jobName, string $time): void
    {
        echo '  ' . $this->paint(" $jobName - KO. Time: $time ", '41', '30') . "\n";
    }

    // Green
    public function info(string $message): void
    {
        $this->success($message);
    }

    public function success(string $message): void
    {
        echo $this->paint($message, '42', '30') . "\n";
    }

    // Red
    public function error(string $message): void
    {
        echo $this->paint($message, '41', '30') . "\n";
    }

    // Yellow
    public function comment(string $message): void
    {
        $this->warning($message);
    }

    public function warning(string $message): void
    {
        echo $this->paint($message, '43', '30') . "\n";
    }
}
