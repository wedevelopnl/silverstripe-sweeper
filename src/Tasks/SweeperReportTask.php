<?php

namespace Sweeper\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use Sweeper\Output\TaskOutput;

class SweeperReportTask extends BuildTask
{
    private static string $segment = 'sweeper-report';

    protected $description = <<<DESCRIPTION
        Runs the following checks and reports their results, this could be used
        during refactoring or code-cleanup to discern where to look.

        1. Looks for defined data objects that have no active instances.
            - Arguments:
                1. no-silverstripe-filter (any value): Report will no longer filter out classes in the Silverstripe// namespace.
                2. namespace-filter (string): Report will filter out classes in the given namespace, note that this is anything that CONTAINS the given namespace, not starts with.
        2. Looks for DataExtensions that are defined but never applied.
        DESCRIPTION;

    public function run($request): void
    {
        $filterSilverstripeClasses = $request->requestVar('no-silverstripe-filter') === null;
        $filterSpecificNamespace = $request->requestVar('namespace-filter');

        $out = TaskOutput::create('Sweeper: report', null);
        $out->info(
            'Filters: '
            . ($filterSilverstripeClasses ? 'SilverStripe\\ classes hidden' : 'SilverStripe\\ classes included')
            . ($filterSpecificNamespace ? ", namespace contains \"{$filterSpecificNamespace}\"" : '')
        );

        $dataObjectSubclasses = ClassInfo::subclassesFor(DataObject::class);
        $dataExtensionSubclasses = array_values(ClassInfo::subclassesFor(DataExtension::class));

        $withoutInstances = [];
        foreach ($dataObjectSubclasses as $dataObjectClass) {
            if ($dataObjectClass === DataObject::class) {
                continue;
            }

            if ($filterSilverstripeClasses && str_contains($dataObjectClass, 'SilverStripe\\')) {
                continue;
            }

            if ($filterSpecificNamespace && !str_contains($dataObjectClass, $filterSpecificNamespace)) {
                continue;
            }

            if ($dataObjectClass::get()->count() === 0) {
                $withoutInstances[] = $dataObjectClass;
            }
        }

        $out->section('DataObjects without instances', count($withoutInstances));
        if ($withoutInstances) {
            $out->items($withoutInstances);
        } else {
            $out->line('Every DataObject has at least one instance.');
        }

        $appliedDataExtensions = [];
        foreach ($dataObjectSubclasses as $dataObjectClass) {
            /** @var DataObject $singleton */
            $singleton = $dataObjectClass::singleton();
            $appliedExtensions = $singleton->getExtensionInstances();

            foreach ($appliedExtensions as $extension) {
                $className = get_class($extension);

                if (!in_array($className, $dataExtensionSubclasses, true)) {
                    continue;
                }

                $appliedDataExtensions[] = $className;
            }
        }

        $appliedDataExtensions = array_unique($appliedDataExtensions);
        $dataExtensionDiff = array_values(array_filter(
            array_diff($dataExtensionSubclasses, $appliedDataExtensions),
            static function ($className) use ($filterSilverstripeClasses, $filterSpecificNamespace) {
                if ($filterSilverstripeClasses && $filterSpecificNamespace) {
                    return !str_contains($className, 'SilverStripe\\') && str_contains($className, $filterSpecificNamespace);
                }

                if ($filterSilverstripeClasses) {
                    return !str_contains($className, 'SilverStripe\\');
                }

                if ($filterSpecificNamespace) {
                    return str_contains($className, $filterSpecificNamespace);
                }

                return true;
            }
        ));

        $out->section('DataExtensions never applied', count($dataExtensionDiff));
        if ($dataExtensionDiff) {
            $out->items($dataExtensionDiff);
            $out->info(
                'NOTE: A DataExtension could be listed even though you have it applied somewhere; this is '
                . 'most likely a case of a DataExtension that can safely extend Extension instead. You can at '
                . 'least safely conclude that there are no subclasses of DataObject with that extension.'
            );
        } else {
            $out->line('All DataExtensions are applied at least once.');
        }

        $out->summary([
            'DataObjects without instances' => count($withoutInstances),
            'DataExtensions never applied' => count($dataExtensionDiff),
        ]);
        $out->finish();
    }
}
