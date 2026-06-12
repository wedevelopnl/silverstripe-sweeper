<?php

namespace Sweeper\Output;

class CliOutput extends TaskOutput
{
    protected function header(string $title): void
    {
        echo "\n{$title}\n" . str_repeat('=', mb_strlen($title)) . "\n";

        if ($this->dryRun !== null) {
            echo 'Mode: ' . ($this->dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n";
        }
    }

    public function line(string $message): void
    {
        echo $this->stamp() . $message . "\n";
    }

    public function info(string $message): void
    {
        $this->line($message);
    }

    public function warning(string $message): void
    {
        echo $this->stamp() . '!! ' . $message . "\n";
    }

    public function section(string $title, ?int $count = null, bool $open = true): void
    {
        $label = $title . ($count !== null ? " ({$count})" : '');
        echo "\n{$label}\n" . str_repeat('-', mb_strlen($label)) . "\n";
    }

    public function items(array $items): void
    {
        foreach ($items as $item) {
            echo "  - {$item}\n";
        }
    }

    public function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach (array_merge([$headers], $rows) as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string)$cell));
            }
        }

        $render = function (array $row) use ($widths): string {
            $cells = [];
            foreach (array_values($row) as $i => $cell) {
                $cells[] = str_pad((string)$cell, $widths[$i]);
            }
            return '  ' . rtrim(implode('  ', $cells));
        };

        echo $render($headers) . "\n";
        foreach ($rows as $row) {
            echo $render($row) . "\n";
        }
    }

    public function summary(array $stats): void
    {
        $lines = [];
        foreach ($stats as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }
        $inner = max(array_map('mb_strlen', $lines));

        echo "\n+" . str_repeat('-', $inner + 2) . "+\n";
        foreach ($lines as $line) {
            echo '| ' . str_pad($line, $inner) . " |\n";
        }
        echo '+' . str_repeat('-', $inner + 2) . "+\n";
    }

    public function action(string $label, string $command): void
    {
        echo "\n> {$label}: {$command}\n";
    }

    public function finish(): void
    {
        echo "\n";
    }

    private function stamp(): string
    {
        return (new \DateTime())->format(DATE_ATOM) . ': '
            . ($this->dryRun === true ? '(dry-run) ' : '');
    }
}
