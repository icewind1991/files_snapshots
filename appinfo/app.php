<?php

$eventDispatcher = \OC::$server->getEventDispatcher();
$eventDispatcher->addListener('OCA\Files::loadAdditionalScripts', function () {
	\OCP\Util::addScript('files_snapshots', 'merged');
	\OCP\Util::addStyle('files_snapshots', 'snapshots');
});