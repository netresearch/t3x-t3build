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

/**
 * Provider to clear the cache
 *
 * @package t3build
 * @author  Christian Opitz <christian.opitz@netresearch.de>
 * @license http://opensource.org/licenses/gpl-license GPLv2 or later
 */
class tx_t3build_provider_clearcache extends tx_t3build_provider_abstract
{
    /**
     * The command to execute - can be one of:
     * "pages"   - Clears cache for all pages
     * "all"     - Clears all cache_tables
     * [integer] - Clears cache for the page with this id
     *
     * @arg
     * @var string
     */
    protected $cmd = 'all';

    /**
     * Action to clear the typo3 cache
     *
     * @return void
     */
    public function typo3Action()
    {
        /* @var $TceMain t3lib_TCEmain */
        $TceMain = t3lib_div::makeInstance('t3lib_TCEmain');
        $TceMain->stripslashes_values = 0;
        $TceMain->start(Array(),Array());
        $TceMain->admin = true;
        $TceMain->clear_cacheCmd($this->cmd);

        $this->_debug(
            'Executed t3lib_TCEmain::clear_cacheCmd("' . $this->cmd . '")' . "\n"
        );
    }
}
?>
