<?php

namespace Sweeper\Output;

use SilverStripe\Core\Convert;

class HtmlOutput extends TaskOutput
{
    private bool $sectionOpen = false;

    protected function header(string $title): void
    {
        echo $this->styles();
        echo '<div class="sweeper-report">';
        echo '<h1>' . $this->esc($title);

        if ($this->dryRun !== null) {
            echo ' <span class="sweeper-badge ' . ($this->dryRun ? 'dry' : 'exec') . '">'
                . ($this->dryRun ? 'DRY-RUN' : 'EXECUTE') . '</span>';
        }

        echo '</h1>';
        echo '<p class="sweeper-meta">Started: ' . (new \DateTime())->format(DATE_ATOM) . '</p>';
    }

    public function line(string $message): void
    {
        echo '<p>' . $this->esc($message) . '</p>';
    }

    public function info(string $message): void
    {
        echo '<div class="sweeper-card info">' . $this->esc($message) . '</div>';
    }

    public function warning(string $message): void
    {
        echo '<div class="sweeper-card warn">' . $this->esc($message) . '</div>';
    }

    public function section(string $title, ?int $count = null, bool $open = true): void
    {
        $this->closeSection();
        $this->sectionOpen = true;

        echo '<details class="sweeper-section"' . ($open ? ' open' : '') . '>'
            . '<summary>' . $this->esc($title)
            . ($count !== null ? ' <span class="sweeper-count">' . $count . '</span>' : '')
            . '</summary>';
    }

    public function items(array $items): void
    {
        echo '<ul>';
        foreach ($items as $item) {
            echo '<li>' . $this->esc((string)$item) . '</li>';
        }
        echo '</ul>';
    }

    public function table(array $headers, array $rows): void
    {
        echo '<table><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>' . $this->esc((string)$header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . $this->esc((string)$cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function summary(array $stats): void
    {
        $this->closeSection();

        echo '<div class="sweeper-summary"><h2>Summary</h2><table>';
        foreach ($stats as $label => $value) {
            echo '<tr><th>' . $this->esc((string)$label) . '</th>'
                . '<td>' . $this->esc((string)$value) . '</td></tr>';
        }
        echo '</table></div>';
    }

    public function action(string $label, string $command): void
    {
        $id = 'sweeper-action-' . substr(md5($command), 0, 8);

        echo '<div class="sweeper-action"><span>' . $this->esc($label) . '</span>'
            . '<code id="' . $id . '">' . $this->esc($command) . '</code>'
            . '<button type="button" onclick="navigator.clipboard.writeText('
            . "document.getElementById('" . $id . "').textContent)\">Copy</button></div>";
    }

    public function finish(): void
    {
        $this->closeSection();
        echo '<p class="sweeper-meta">Finished: ' . (new \DateTime())->format(DATE_ATOM) . '</p>';
        echo '</div>';
    }

    private function closeSection(): void
    {
        if ($this->sectionOpen) {
            echo '</details>';
            $this->sectionOpen = false;
        }
    }

    private function esc(string $value): string
    {
        return Convert::raw2xml($value);
    }

    private function styles(): string
    {
        return <<<'HTML'
            <style>
            .sweeper-report{font-family:system-ui,-apple-system,sans-serif;max-width:960px;margin:1rem auto;color:#0f172a}
            .sweeper-report h1{font-size:1.4rem}
            .sweeper-badge{font-size:.7rem;font-weight:700;padding:.2rem .55rem;border-radius:999px;vertical-align:middle}
            .sweeper-badge.dry{background:#fef3c7;color:#92400e}
            .sweeper-badge.exec{background:#fee2e2;color:#991b1b}
            .sweeper-meta{color:#64748b;font-size:.8rem}
            .sweeper-section{border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .75rem;margin:.5rem 0}
            .sweeper-section summary{cursor:pointer;font-weight:600}
            .sweeper-count{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:0 .5rem;font-size:.8rem}
            .sweeper-card{border-left:4px solid;padding:.5rem .75rem;margin:.5rem 0;border-radius:0 6px 6px 0}
            .sweeper-card.info{border-color:#2563eb;background:#eff6ff}
            .sweeper-card.warn{border-color:#dc2626;background:#fef2f2}
            .sweeper-report table{border-collapse:collapse;margin:.25rem 0}
            .sweeper-report th,.sweeper-report td{border:1px solid #e2e8f0;padding:.25rem .6rem;text-align:left;font-size:.85rem;vertical-align:top}
            .sweeper-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .75rem;margin:.75rem 0}
            .sweeper-summary h2{font-size:1rem;margin:.25rem 0}
            .sweeper-action{display:flex;gap:.75rem;align-items:center;background:#0f172a;color:#e2e8f0;border-radius:8px;padding:.5rem .75rem;margin:.5rem 0}
            .sweeper-action code{color:#86efac;background:transparent}
            .sweeper-action button{margin-left:auto;cursor:pointer}
            </style>
            HTML;
    }
}
