<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Output;

use Wtyd\Alfil\Output\Concerns\OutputHandlerWaitingNoOp;
use Wtyd\Alfil\Utils\Printer;

/**
 * Text output with real-time streaming for sequential execution.
 *
 * Used when format=text and processes<=1 (or single job).
 * Prints tool output as it arrives, with header separators between jobs.
 */
class StreamingTextOutputHandler implements OutputHandler
{
    use OutputHandlerWaitingNoOp;

    private Printer $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    public function onFlowStart(int $totalJobs): void
    {
    }

    public function onJobStart(string $jobName): void
    {
        $this->printer->line('  ' . $this->printer->paint("--- $jobName ---", '1'));
    }

    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
        echo $chunk;
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $this->printer->jobSuccess($jobName, $time);
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $this->printer->jobError($jobName, $time);
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        $this->printer->line("  ⏩ $jobName ($reason)");
    }

    public function onJobDryRun(string $jobName, string $command): void
    {
        $this->printer->line('  ' . $this->printer->paint($jobName, '36'));
        $this->printer->line("     $command");
    }

    public function flush(): void
    {
    }
}
