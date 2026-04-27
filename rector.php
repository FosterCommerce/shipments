<?php

declare(strict_types=1);

use fostercommerce\rector\RectorConfig;
use fostercommerce\rector\SetList;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchExprVariableRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchMethodCallReturnTypeRector;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/src',
		__FILE__,
	])
	->withSets([SetList::CRAFT_CMS_50])
	->withSkip([
		// Both rules ship with a build-time `RectorPrefix202411` leak that rewrites
		// foreach vars to `$fooRectorPrefix202411Bar` in Rector 1.x.
		RenameForeachValueVariableToMatchExprVariableRector::class,
		RenameForeachValueVariableToMatchMethodCallReturnTypeRector::class,
		// DTO constructor params are named for clarity at call sites (e.g. $axis, $history)
		// rather than their full type name.
		RenamePropertyToMatchTypeRector::class => [
			__DIR__ . '/src/models/ShipmentEmailContext.php',
			__DIR__ . '/src/models/ShipmentStatusHistoryEntry.php',
		],
	]);
