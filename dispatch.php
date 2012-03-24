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
require_once t3lib_extMgm::extPath('t3build').'provider/class.abstract.php';

/**
 * General CLI dispatcher for the t3build extension.
 *
 * @package t3build
 * @author Oliver Hader <oliver.hader@aoemedia.de>
 */
class tx_t3build_dispatch extends t3lib_cli {
	const ExtKey = 't3build';
	const Mask_ClassName = 'tx_t3build_provider_%s';
	const Mask_FileName = 'class.%s.php';

	/**
	 * Creates this object.
	 */
	public function __construct() {
		parent::__construct();
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
		$controller = (string)$this->cli_args['_DEFAULT'][1];
		$action = (string)$this->cli_args['_DEFAULT'][2];
		$controllers = array();
		$classPath = t3lib_extMgm::extPath(self::ExtKey).'provider';

		if (isset($this->cli_args['--debug'])) {
		    restore_exception_handler();
		    restore_error_handler();
		}

		if ($controller) {
			$controllers[] = $controller;
		} else {
		    $this->cli_echo('No command provided - please specify one of the following commands:'.PHP_EOL, true);
            $directory = new DirectoryIterator($classPath);
            $pattern = '/^class\.'.sprintf(self::Mask_ClassName, '([a-zA-Z0-9]+)').'\.php$/';
            foreach ($directory as $file) {
                /* @var $file SplFileInfo */
                if ($file->isFile() && preg_match($pattern, $file->getFilename(), $match) && $match[1] != 'abstract') {
                    echo $match[1].PHP_EOL;
                }
            }
            return;
		}

		$className = sprintf(self::Mask_ClassName, $controller);
		if (!class_exists($className)) {
		    $file = $classPath.DIRECTORY_SEPARATOR.sprintf(self::Mask_FileName, $controller);
		    if (!file_exists($file)) {
		        die('Invalid command "'.$controller.'"');
		    }
			t3lib_div::requireOnce($file);
		}
		/* @var $instance tx_t3build_abstractController */
		$instance = t3lib_div::makeInstance($className);
		if (!$instance instanceof tx_t3build_provider_abstract) {
		    echo 'Controller '.$controller.' must extend tx_t3build_provider_abstract';
		    exit;
		}
		$instance->init($this->cli_args);
		$instance->run($action);

		return $result;
	}
}

echo t3lib_div::makeInstance('tx_t3build_dispatch')->dispatch();