<?php
/**
 * Provider to clear the cache
 *
 * @package t3build
 * @author Christian Opitz <co@netzelf.de>
 *
 */
class tx_t3build_provider_clearcache extends tx_t3build_provider_abstract {
	/**
	 * The command to execute - can be one of:
	 * "pages"   - Clears cache for all pages
	 * "all"     - Clears all cache_tables
	 * [integer] - Clears cache for the page with this id
	 * @arg
	 * @var string
	 */
	protected $cmd = 'all';

	public function typo3Action()
	{
		/* @var $TceMain t3lib_TCEmain */
		$TceMain = t3lib_div::makeInstance('t3lib_TCEmain');
		$TceMain->stripslashes_values = 0;
		$TceMain->start(Array(),Array());
		$TceMain->admin = true;
		$TceMain->clear_cacheCmd($this->cmd);

        $this->_debug('Executed t3lib_TCEmain::clear_cacheCmd("'.$this->cmd.'")'."\n");
	}
}
