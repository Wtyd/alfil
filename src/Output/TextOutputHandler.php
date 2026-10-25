<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Output;

use Wtyd\Alfil\Output\Concerns\OutputHandlerWaitingNoOp;
use Wtyd\Alfil\Utils\Printer;

/**
 * Default text output: success lines print in real-time, error details
 * are buffered and printed grouped at the end via flush().
 */
class TextOutputHandler implements OutputHandler
{
    use OutputHandlerWaitingNoOp;

    private Printer $printer;

    /** @var array<array{jobName: string, output: string}> */
    private array $errorBuffer = [];

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    public function onFlowStart(int $totalJobs): void
    {
    }

    public function onJobStart(string $jobName): void
    {
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) No-op in parallel text mode */
    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $this->printer->jobSuccess($jobName, $time);
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $this->printer->jobError($jobName, $time);
        $this->errorBuffer[] = ['jobName' => $jobName, 'output' => $output];
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
        if (empty($this->errorBuffer)) {
            return;
        }

        $this->printer->emptyLine();

        foreach ($this->errorBuffer as $entry) {
            if (!empty(trim($entry['output']))) {
                $this->printer->framedErrorBlock($entry['jobName'], $entry['output']);
                $this->printer->emptyLine();
            }
        }

        $this->errorBuffer = [];
    }
}
