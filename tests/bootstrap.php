<?php

// use the server's test bootstrap when available, it loads all apps and the test helpers
// that integration tests (shares, external storage) rely on
if (file_exists(__DIR__ . '/../../../tests/bootstrap.php')) {
	require_once __DIR__ . '/../../../tests/bootstrap.php';
} else {
	define('PHPUNIT_RUN', 1);

	require_once __DIR__ . '/../../../lib/base.php';

	\OC::$composerAutoloader->addPsr4('Test\\', OC::$SERVERROOT . '/tests/lib/', true);
	\OC::$composerAutoloader->addPsr4('Tests\\', OC::$SERVERROOT . '/tests/', true);
}

OC_App::loadApp('files_snapshots');

OC_Hook::clear();
