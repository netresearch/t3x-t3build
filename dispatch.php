<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009 AOE media GmbH <dev@aoemedia.de>
*  All rights reserved
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

if (!defined ('TYPO3_cliMode')) {
	die('Access denied: CLI only.');
}

require_once PATH_t3lib . 'class.t3lib_cli.php';
require_once t3lib_extMgm::extPath('t3build').'classes/class.tx_t3build_providerInfo.php';

/**
 * General CLI dispatcher for the t3build extension.
 *
 * @package t3build
 * @author Oliver Hader <oliver.hader@aoemedia.de>
 */
class tx_t3build_dispatch extends t3lib_cli {
	const ExtKey = 't3build';

	/**
	 * @var tx_t3build_providerInfo
	 */
	protected $providerInfo;

	/**
	 * Creates this object.
	 */
	public function __construct() {
		parent::__construct();
		$this->providerInfo = t3lib_div::makeInstance('tx_t3build_providerInfo');
		$this->cli_help = array_merge($this->cli_help, array(
			'name' => 'tx_t3build_dispatch',
			'synopsis' => self::ExtKey . ' controller action ###OPTIONS###',
			'description' => '',
			'examples' => 'typo3/cli_dispatch.phpsh ' . self::ExtKey . ' database updateStructure',
			'author' => '(c) 2010 AOE media GmbH <dev@aoemedia.de>',
		));
	}

	/**
	 * Dispatches the requested actions to the accordant controller.
	 *
	 * @return void
	 */
	public function dispatch() {
		$provider = (string)$this->cli_args['_DEFAULT'][1];
		$action = (string)$this->cli_args['_DEFAULT'][2];

		if (isset($this->cli_args['--debug'])) {
		    restore_exception_handler();
		    restore_error_handler();
		}

		if (!$provider) {
		    $this->cli_echo('No command provided - please specify one of the following commands:'.PHP_EOL, true);
            echo implode(PHP_EOL, $this->providerInfo->getProviders()).PHP_EOL;
            return;
		}

		$instance = $this->providerInfo->getProviderInstance($provider);
		$instance->init($this->cli_args);
		$instance->run($action);
	}
}

echo t3lib_div::makeInstance('tx_t3build_dispatch')->dispatch();