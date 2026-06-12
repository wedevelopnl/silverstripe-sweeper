<?php

namespace Sweeper\Output;

use SilverStripe\Control\Director;

/**
 * Semantic task output. Tasks state WHAT to report; the renderer decides HOW.
 *
 * Streaming by design: every method echoes immediately, so long-running tasks
 * show live progress. Consequence: summary() comes at the end, never on top.
 *
 * $dryRun semantics: true shows a DRY-RUN marker, false shows EXECUTE,
 * null shows neither (read-only tasks like sweeper-report).
 */
abstract class TaskOutput
{
    protected ?bool $dryRun;

    public static function create(string $title, ?bool $dryRun): self
    {
        return Director::is_cli()
            ? new CliOutput($title, $dryRun)
            : new HtmlOutput($title, $dryRun);
    }

    public function __construct(string $title, ?bool $dryRun)
    {
        $this->dryRun = $dryRun;
        $this->header($title);
    }

    abstract protected function header(string $title): void;

    abstract public function line(string $message): void;

    abstract public function info(string $message): void;

    abstract public function warning(string $message): void;

    /**
     * Starts a section; automatically closes the previous one.
     * $open only affects the HTML renderer (collapsed vs expanded).
     */
    abstract public function section(string $title, ?int $count = null, bool $open = true): void;

    /** @param string[] $items */
    abstract public function items(array $items): void;

    /**
     * @param string[] $headers
     * @param array<array<string|int>> $rows
     */
    abstract public function table(array $headers, array $rows): void;

    /** @param array<string, string|int> $stats */
    abstract public function summary(array $stats): void;

    abstract public function action(string $label, string $command): void;

    abstract public function finish(): void;
}
