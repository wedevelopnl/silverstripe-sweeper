<?php

namespace Sweeper\Tests\Output;

use PHPUnit\Framework\TestCase;
use Sweeper\Output\HtmlOutput;

class HtmlOutputTest extends TestCase
{
    private function render(callable $calls, ?bool $dryRun = true): string
    {
        ob_start();
        $out = new HtmlOutput('Test task', $dryRun);
        $calls($out);
        return ob_get_clean();
    }

    public function testHeaderHasBadgeAndStyles(): void
    {
        $html = $this->render(fn ($out) => $out->finish());

        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('DRY-RUN', $html);
        $this->assertStringContainsString('Test task', $html);
    }

    public function testNullDryRunHasNoBadge(): void
    {
        $html = $this->render(fn ($out) => $out->finish(), null);

        // The CSS always defines .sweeper-badge; assert no badge ELEMENT is rendered.
        $this->assertStringNotContainsString('<span class="sweeper-badge', $html);
    }

    public function testSectionIsCollapsibleWithCount(): void
    {
        $html = $this->render(function ($out) {
            $out->section('Droppable tables', 73);
            $out->items(['old_a']);
            $out->finish();
        });

        $this->assertStringContainsString('<details class="sweeper-section" open>', $html);
        $this->assertStringContainsString('<span class="sweeper-count">73</span>', $html);
        $this->assertStringContainsString('<li>old_a</li>', $html);
    }

    public function testCollapsedSectionHasNoOpenAttribute(): void
    {
        $html = $this->render(function ($out) {
            $out->section('Per class', null, false);
            $out->finish();
        });

        $this->assertStringContainsString('<details class="sweeper-section">', $html);
    }

    public function testSectionsAutoClose(): void
    {
        $html = $this->render(function ($out) {
            $out->section('One');
            $out->section('Two');
            $out->summary(['A' => 1]);
            $out->finish();
        });

        $this->assertSame(2, substr_count($html, '</details>'));
    }

    public function testEverythingIsEscaped(): void
    {
        $html = $this->render(function ($out) {
            $out->line('<script>alert(1)</script>');
            $out->items(['<b>x</b>']);
            $out->table(['<i>h</i>'], [['<u>c</u>']]);
            $out->finish();
        });

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringNotContainsString('<u>c</u>', $html);
    }

    public function testActionHasCopyButton(): void
    {
        $html = $this->render(fn ($out) => $out->action('CLI', 'run=yes token=abc'));

        $this->assertStringContainsString('run=yes token=abc', $html);
        $this->assertStringContainsString('navigator.clipboard.writeText', $html);
    }

    public function testWarningRendersAsCard(): void
    {
        $html = $this->render(fn ($out) => $out->warning('REFUSED'));

        $this->assertStringContainsString('sweeper-card warn', $html);
        $this->assertStringContainsString('REFUSED', $html);
    }
}
