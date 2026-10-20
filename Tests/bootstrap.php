<?php
require dirname(__DIR__) . '/vendor/autoload.php';
putenv('TYPO3_PATH_ROOT=' . dirname(__DIR__) . '/public');
(new TYPO3\TestingFramework\Core\Testbase())->defineOriginalRootPath();
