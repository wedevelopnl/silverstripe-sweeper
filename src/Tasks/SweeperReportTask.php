<?php

namespace Sweeper\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;

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

        $dataObjectSubclasses = ClassInfo::subclassesFor(DataObject::class);
        $dataExtensionSubclasses = array_values(ClassInfo::subclassesFor(DataExtension::class));

        echo "\n";
        echo "Checking for data objects with no instances \n";
        echo "--------------------------------------------\n";
        $noInstanceDataObjectCount = 0;

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
                echo "$dataObjectClass has no active instances. \n";
                $noInstanceDataObjectCount++;
            }
        }
        echo "\nFound $noInstanceDataObjectCount dataObjects without any instances \n";

        echo "\n";
        echo "Checking for data extensions that are never applied \n";
        echo "--------------------------------------------\n";
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
        $dataExtensionDiff = array_filter(
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
        );

        if (!count($dataExtensionDiff)) {
            echo "All DataExtensions are applied at least once. \n\n";
        } else {
            echo "Found ". count($dataExtensionDiff) . " DataExtensions that are never applied.\n\n";

            foreach ($dataExtensionDiff as $className) {
                echo "$className\n";
            }

            echo "\nNOTE: A DataExtension could be listed even though you have it applied somewhere, this is\n";
            echo "most likely a case of a DataExtension that can safely extend Extension instead. You can at \n";
            echo "least safely conclude that there are no subclasses of DataObject with that extension.\n";
        }
    }
}
