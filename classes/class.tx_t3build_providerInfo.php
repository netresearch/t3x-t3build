<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Netresearch GmbH & Co. KG <typo3-2013@netresearch.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

require_once t3lib_extMgm::extPath('t3build').'provider/class.abstract.php';

/**
 * Information about all available providers
 *
 * @package t3build
 * @author  Christian Opitz <christian.opitz@netresearch.de>
 * @license http://opensource.org/licenses/gpl-license GPLv2 or later
 */
class tx_t3build_providerInfo
{
    /**
     * The extension key, needed to get the class path
     *
     * @var string
     */
    const ExtKey = 't3build';

    /**
     * General mask for the providers to determine if a provider is existing and to
     * create provider objects.
     *
     * @var string
     */
    const Mask_ClassName = 'tx_t3build_provider_%s';

    /**
     * General mask for the provider files, needed to load them
     *
     * @var string
     */
    const Mask_FileName = 'class.%s.php';

    /**
     * Array containing providers from other extensions
     *
     * @var array
     */
    protected $extProviders;

    /**
     * Array containing the providers distributed by t3build
     *
     * @var array
     */
    protected $providers;

    /**
     * Get the class path for the providers
     *
     * @return string The class path
     */
    public function getClassPath()
    {
        return t3lib_extMgm::extPath(self::ExtKey).'provider';
    }

    /**
     * Get the providers from other extensions
     *
     * @return array An array with the providers
     */
    public function getExtProviders()
    {
        if (!$this->extProviders) {
            $this->extProviders = (array) $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']
                ['t3build']['providers'];
        }

        return $this->extProviders;
    }

    /**
     * Get the internal providers
     *
     * @return array An array with the internal providers
     */
    public function getProviders()
    {
        if (!$this->providers) {
            $directory = new DirectoryIterator($this->getClassPath());
            $pattern = '/^class\.([a-zA-Z0-9]+)\.php$/';
            $providers = array_keys($this->getExtProviders());

            foreach ($directory as $file) {
                /* @var $file SplFileInfo */
                if ($file->isFile()
                    && preg_match($pattern, $file->getFilename(), $match)
                    && $match[1] != 'abstract'
                ) {
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
    * Instantiate a provider
    *
    * @param string $provider Provider name
    *
    * @return tx_t3build_provider_abstract The instantiated provider or null on error
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
                $file = $this->getClassPath() . DIRECTORY_SEPARATOR
                    . sprintf(self::Mask_FileName, $provider);
                if (!file_exists($file)) {
                    die('Invalid provider "'.$provider.'"'.PHP_EOL);
                }
                t3lib_div::requireOnce($file);
            }
            $instance = t3lib_div::makeInstance($className);
        }

        if (!$instance instanceof tx_t3build_provider_abstract) {
            echo 'Controller ' . $provider
                . ' must extend tx_t3build_provider_abstract';
            exit;
        }

        return $instance;
    }
}
?>
