<?php

require_once t3lib_extMgm::extPath('t3build').'provider/class.abstract.php';

class tx_t3build_providerInfo
{
	const ExtKey = 't3build';
	const Mask_ClassName = 'tx_t3build_provider_%s';
	const Mask_FileName = 'class.%s.php';

	protected $extProviders;
	protected $providers;

	public function getClassPath()
	{
		return t3lib_extMgm::extPath(self::ExtKey).'provider';
	}

	public function getExtProviders()
	{
	    if (!$this->extProviders) {
		    $this->extProviders = (array) $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3build']['providers'];
	    }
	    return $this->extProviders;
	}

	public function getProviders()
	{
	    if (!$this->providers) {
            $directory = new DirectoryIterator($this->getClassPath());
            $pattern = '/^class\.([a-zA-Z0-9]+)\.php$/';
            $providers = array_keys($this->getExtProviders());
            foreach ($directory as $file) {
                /* @var $file SplFileInfo */
                if ($file->isFile() && preg_match($pattern, $file->getFilename(), $match) && $match[1] != 'abstract') {
                    $providers[] = $match[1];
                }
            }
            $providers = array_unique($providers);
            sort($providers);
            $this->providers = $providers;
	    }
	    return $this->providers;
	}

	/**
	 * Instanciate a provider
	 *
	 * @param string $provider Provider name
	 * @return tx_t3build_provider_abstract
	 */
	public function getProviderInstance($provider)
	{
		$extProviders = $this->getExtProviders();

		/* @var $instance tx_t3build_provider_abstract */
		if (array_key_exists($provider, $extProviders)) {
		    $instance = t3lib_div::getUserObj($extProviders[$provider], '');
		} else {
    		$className = sprintf(self::Mask_ClassName, $provider);
    		if (!class_exists($className)) {
    		    $file = $this->getClassPath().DIRECTORY_SEPARATOR.sprintf(self::Mask_FileName, $provider);
    		    if (!file_exists($file)) {
    		        die('Invalid provider "'.$provider.'"');
    		    }
    			t3lib_div::requireOnce($file);
    		}
		    $instance = t3lib_div::makeInstance($className);
		}

		if (!$instance instanceof tx_t3build_provider_abstract) {
		    echo 'Controller '.$provider.' must extend tx_t3build_provider_abstract';
		    exit;
		}

		return $instance;
	}
}