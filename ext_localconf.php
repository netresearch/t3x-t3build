<?php
if (!defined('TYPO3_MODE')) {
	die('Access denied.');
}

define('PATH_tx_t3deploy', t3lib_extMgm::extPath($_EXTKEY));

// Register CLI process:
if (TYPO3_MODE == 'BE') {
	$TYPO3_CONF_VARS['SC_OPTIONS']['GLOBAL']['cliKeys'][$_EXTKEY] = array(
		'EXT:' . $_EXTKEY . '/dispatch.php',
		'_CLI_t3deploy'
	);
}

$TYPO3_CONF_VARS['EXTCONF']['lowlevel']['cleanerModules']['get_missing_files'] = array('EXT:t3build/clmods/class.get_missing_files.php:tx_t3build_get_missing_files');