<?php

declare( strict_types=1 );

use Rector\Config\RectorConfig;
use Rector\DowngradePhp80\Rector\FuncCall\DowngradeStrContainsRector;
use Rector\DowngradePhp80\Rector\FuncCall\DowngradeStrEndsWithRector;
use Rector\DowngradePhp80\Rector\FuncCall\DowngradeStrStartsWithRector;
use Rector\Set\ValueObject\DowngradeLevelSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
	->withPaths(
		[
			__DIR__ . '/src',
			__DIR__ . '/tests',
			__DIR__ . '/conduit-crm.php',
			__DIR__ . '/uninstall.php',
		]
	)
	->withSets( [ DowngradeLevelSetList::DOWN_TO_PHP_74 ] )
	->withPhpVersion( PhpVersion::PHP_74 )
	->withSkip(
		[
			// WordPress polyfills these in wp-includes/compat.php as of 5.9, and
			// this plugin requires 6.4. Rector's strpos() rewrite is also wrong
			// for an empty needle, which PHP 8 treats as a match and 7.4 does not.
			DowngradeStrContainsRector::class,
			DowngradeStrStartsWithRector::class,
			DowngradeStrEndsWithRector::class,
		]
	);
