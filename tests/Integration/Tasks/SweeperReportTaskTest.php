<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Tasks;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use Sweeper\Tasks\SweeperReportTask;
use Sweeper\Tests\Integration\Other\NonMatchingNamespaceObject;
use Sweeper\Tests\Integration\Support\AppliedExtension;
use Sweeper\Tests\Integration\Support\NeverAppliedExtension;
use Sweeper\Tests\Integration\Support\ReportEmptyObject;
use Sweeper\Tests\Integration\Support\ReportPopulatedObject;

/**
 * @covers \Sweeper\Tasks\SweeperReportTask
 */
final class SweeperReportTaskTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        ReportEmptyObject::class,
        ReportPopulatedObject::class,
        NonMatchingNamespaceObject::class,
    ];

    /**
     * @param array<string, string> $vars
     */
    private function runReport(array $vars = []): string
    {
        $task = SweeperReportTask::create();
        $request = new HTTPRequest('GET', '', $vars);

        ob_start();
        try {
            $task->run($request);
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }

    public function testReportsDataObjectWithNoInstances(): void
    {
        $output = $this->runReport();

        self::assertStringContainsString(ReportEmptyObject::class, $output);
    }

    public function testExcludesDataObjectThatHasInstances(): void
    {
        $populated = ReportPopulatedObject::create();
        $populated->Title = 'present';
        $populated->write();

        $output = $this->runReport();

        // The no-instance section lists FQCNs followed by " has no active instances."
        self::assertStringNotContainsString(
            ReportPopulatedObject::class . ' has no active instances',
            $output,
        );
    }

    public function testDefaultFilterHidesSilverStripeClasses(): void
    {
        $output = $this->runReport();

        self::assertStringNotContainsString('SilverStripe\\', $output);
    }

    public function testNoSilverstripeFilterIncludesSilverStripeClasses(): void
    {
        $output = $this->runReport(['no-silverstripe-filter' => '1']);

        self::assertStringContainsString('SilverStripe\\', $output);
    }

    public function testNamespaceFilterRestrictsToMatchingNamespace(): void
    {
        $output = $this->runReport(['namespace-filter' => 'Support']);

        self::assertStringContainsString(ReportEmptyObject::class, $output);
        self::assertStringNotContainsString(NonMatchingNamespaceObject::class, $output);
    }

    public function testReportsNeverAppliedDataExtension(): void
    {
        $output = $this->runReport();

        self::assertStringContainsString(NeverAppliedExtension::class, $output);
    }

    public function testExcludesAppliedDataExtension(): void
    {
        $output = $this->runReport();

        self::assertStringNotContainsString(AppliedExtension::class, $output);
    }

    /**
     * The never-applied filter closure has four arms (both filters / silverstripe
     * only / namespace only / neither). Each case asserts two controlled signals:
     *  - $expectSupportExtension: whether NeverAppliedExtension (FQCN contains
     *    "Support") survives the namespace filter;
     *  - $expectSilverStripe: whether any "SilverStripe\" line survives the
     *    silverstripe filter (reliable: a fresh CMS5 test DB always has
     *    zero-instance SilverStripe DataObjects).
     *
     * @dataProvider extensionFilterCases
     * @param array<string, string> $vars
     */
    public function testExtensionFilterMatrix(
        array $vars,
        bool $expectSupportExtension,
        bool $expectSilverStripe,
    ): void {
        $output = $this->runReport($vars);

        if ($expectSupportExtension) {
            self::assertStringContainsString(NeverAppliedExtension::class, $output);
        } else {
            self::assertStringNotContainsString(NeverAppliedExtension::class, $output);
        }

        if ($expectSilverStripe) {
            self::assertStringContainsString('SilverStripe\\', $output);
        } else {
            self::assertStringNotContainsString('SilverStripe\\', $output);
        }
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: bool, 2: bool}>
     */
    public static function extensionFilterCases(): array
    {
        return [
            // namespace-filter=Support keeps NeverAppliedExtension; default ss-filter hides SilverStripe
            'both filters' => [
                ['namespace-filter' => 'Support'],
                true,
                false,
            ],
            // ss-filter only: NeverAppliedExtension (non-SS) kept, SilverStripe hidden
            'silverstripe only' => [
                [],
                true,
                false,
            ],
            // namespace-filter only (ss-filter off): Support kept, SilverStripe hidden because
            // namespace-filter restricts DataObjects and Extensions to "Support" namespace only;
            // the silverstripe flag being off doesn't override the namespace restriction.
            'namespace only' => [
                ['namespace-filter' => 'Support', 'no-silverstripe-filter' => '1'],
                true,
                false,
            ],
            // neither filter: Support kept, SilverStripe shown
            'neither' => [
                ['no-silverstripe-filter' => '1'],
                true,
                true,
            ],
        ];
    }
}
