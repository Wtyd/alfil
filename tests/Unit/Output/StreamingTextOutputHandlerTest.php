<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Concerns\CapturesStdout;
use Wtyd\Alfil\Output\StreamingTextOutputHandler;
use Wtyd\Alfil\Utils\Printer;

class StreamingTextOutputHandlerTest extends UnitTestCase
{
    use CapturesStdout;

    /** @test */
    public function on_job_start_prints_header()
    {
        $printer = $this->createMock(Printer::class);
        $printer->method('paint')->willReturnArgument(0);
        $printer->expects($this->once())
            ->method('line')
            ->with($this->stringContains('--- phpstan_src ---'));

        $handler = new StreamingTextOutputHandler($printer);
        $handler->onJobStart('phpstan_src');
    }

    /** @test */
    public function on_job_output_echoes_chunk()
    {
        $printer = $this->createMock(Printer::class);
        $handler = new StreamingTextOutputHandler($printer);

        $output = $this->captureStdoutRaw(function () use ($handler) {
            $handler->onJobOutput('phpstan_src', 'some output chunk', false);
        });

        $this->assertSame('some output chunk', $output);
    }

    /** @test */
    public function on_job_success_prints_status()
    {
        $printer = $this->createMock(Printer::class);
        $printer->expects($this->once())
            ->method('jobSuccess')
            ->with('phpstan_src', '1.23s');

        $handler = new StreamingTextOutputHandler($printer);
        $handler->onJobSuccess('phpstan_src', '1.23s');
    }

    /** @test */
    public function on_job_error_prints_status_without_output()
    {
        $printer = $this->createMock(Printer::class);
        $printer->expects($this->once())
            ->method('jobError')
            ->with('phpstan_src', '500ms');

        $handler = new StreamingTextOutputHandler($printer);
        $handler->onJobError('phpstan_src', '500ms', 'already streamed');
    }

    /** @test */
    public function flush_is_noop()
    {
        $printer = $this->createMock(Printer::class);
        $printer->expects($this->never())->method($this->anything());

        $handler = new StreamingTextOutputHandler($printer);
        $handler->flush();
    }

    /** @test */
    public function on_flow_start_is_noop()
    {
        $printer = $this->createMock(Printer::class);
        $printer->expects($this->never())->method($this->anything());

        $handler = new StreamingTextOutputHandler($printer);
        $handler->onFlowStart(5);
    }

    /** @test */
    public function on_job_skipped_prints_skip_line_with_reason()
    {
        $printer = $this->createMock(Printer::class);
        $printer->expects($this->once())
            ->method('line')
            ->with($this->logicalAnd(
                $this->stringContains('phpcs_src'),
                $this->stringContains('no staged files')
            ));

        $handler = new StreamingTextOutputHandler($printer);
        $handler->onJobSkipped('phpcs_src', 'no staged files');
    }

    /** @test */
    public function on_job_dry_run_prints_job_name_and_command()
    {
        $printer = $this->createMock(Printer::class);
        $printer->method('paint')->willReturnArgument(0);
        $calls = [];
        $printer->expects($this->exactly(2))
            ->method('line')
            ->willReturnCallback(function ($line) use (&$calls) {
                $calls[] = $line;
            });

        $handler = new StreamingTextOutputHandler($printer);
        $handler->onJobDryRun('phpstan_src', 'vendor/bin/phpstan analyse src');

        $this->assertStringContainsString('phpstan_src', $calls[0]);
        $this->assertStringContainsString('vendor/bin/phpstan analyse src', $calls[1]);
    }
}
