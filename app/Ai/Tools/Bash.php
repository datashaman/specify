<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class Bash implements Tool
{
    private const DEFAULT_TIMEOUT = 120;

    private const MAX_TIMEOUT = 600;

    private const MAX_OUTPUT_BYTES = 64_000;

    public function __construct(public Sandbox $sandbox) {}

    public function description(): Stringable|string
    {
        return 'Run a shell command in the working directory. Default timeout '.self::DEFAULT_TIMEOUT
            .'s, max '.self::MAX_TIMEOUT.'s. Output is truncated to '.self::MAX_OUTPUT_BYTES.' bytes. '
            .'Use this for running tests, package managers, git read commands, etc. Do not push, open PRs, or merge.';
    }

    public function handle(Request $request): Stringable|string
    {
        $command = trim((string) ($request['command'] ?? ''));
        if ($command === '') {
            return 'Error: empty command.';
        }

        $timeout = (int) ($request['timeout'] ?? self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) {
            $timeout = self::DEFAULT_TIMEOUT;
        }
        $timeout = min($timeout, self::MAX_TIMEOUT);

        $process = Process::fromShellCommandline($command, $this->sandbox->root);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return "Error: command timed out after {$timeout}s.\n".$this->trim($process->getOutput().$process->getErrorOutput());
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $exit = $process->getExitCode() ?? -1;

        $body = '';
        if ($stdout !== '') {
            $body .= "stdout:\n".$this->trim($stdout)."\n";
        }
        if ($stderr !== '') {
            $body .= "stderr:\n".$this->trim($stderr)."\n";
        }
        if ($body === '') {
            $body = "(no output)\n";
        }

        return "exit {$exit}\n".$body;
    }

    private function trim(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return rtrim($output);
        }

        $head = substr($output, 0, (int) (self::MAX_OUTPUT_BYTES * 0.4));
        $tail = substr($output, -(int) (self::MAX_OUTPUT_BYTES * 0.4));

        return rtrim($head)."\n... [truncated ".(strlen($output) - strlen($head) - strlen($tail))." bytes] ...\n".ltrim($tail);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()->description('Shell command (executed via /bin/sh -c).')->required(),
            'timeout' => $schema->integer()->description('Timeout in seconds (max '.self::MAX_TIMEOUT.').'),
        ];
    }
}
