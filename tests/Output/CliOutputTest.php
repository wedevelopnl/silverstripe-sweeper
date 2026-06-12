<?php

namespace Sweeper\Tests\Output;

use PHPUnit\Framework\TestCase;
use Sweeper\Output\CliOutput;

class CliOutputTest extends TestCase
{
    private function render(callable $calls, ?bool $dryRun = true): string
    {
        ob_start();
        $out = new CliOutput('Test task', $dryRun);
        $calls($out);
        return ob_get_clean();
    }

    public function testHeaderShowsTitleAndMode(): void
    {
        $text = $this->render(fn ($out) => $out->finish());

        $this->assertStringContainsString('Test task', $text);
        $this->assertStringContainsString('Mode: DRY-RUN', $text);
    }

    public function testNullDryRunOmitsModeAndPrefix(): void
    {
        $text = $this->render(fn ($out) => $out->line('hello'), null);

        $this->assertStringNotContainsString('Mode:', $text);
        $this->assertStringNotContainsString('(dry-run)', $text);
        $this->assertStringContainsString('hello', $text);
    }

    public function testLineIsTimestampedAndPrefixed(): void
    {
        $text = $this->render(fn ($out) => $out->line('working'));

        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T.*: \(dry-run\) working/', $text);
    }

    public function testWarningHasMarker(): void
    {
        $text = $this->render(fn ($out) => $out->warning('danger'));

        $this->assertStringContainsString('!! danger', $text);
    }

    public function testSectionItemsAndTable(): void
    {
        $text = $this->render(function ($out) {
            $out->section('Droppable tables', 2);
            $out->items(['old_a', 'old_b']);
            $out->section('Droppable columns', 1);
            $out->table(['Table', 'Columns'], [['page', 'Foo, Bar']]);
        });

        $this->assertStringContainsString("Droppable tables (2)\n--------------------", $text);
        $this->assertStringContainsString('  - old_a', $text);
        $this->assertStringContainsString('Table', $text);
        $this->assertStringContainsString('page', $text);
    }

    public function testSummaryIsFramed(): void
    {
        $text = $this->render(fn ($out) => $out->summary(['Tables' => 73, 'Columns' => 164]));

        $this->assertStringContainsString('| Tables: 73', $text);
        $this->assertStringContainsString('+--', $text);
    }

    public function testActionShowsCommand(): void
    {
        $text = $this->render(fn ($out) => $out->action('CLI', 'run=yes token=abc'));

        $this->assertStringContainsString('> CLI: run=yes token=abc', $text);
    }
}
