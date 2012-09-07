<?php
/**
 * Provider to copy templavoila datastructure to files and link
 * them with the template objects
 *
 * @package t3build
 * @author Christian Opitz <co@netzelf.de>
 */
class tx_t3build_provider_tvstaticds extends tx_t3build_provider_abstract
{
    /**
     * The extension to which the files should be written
     * @arg
     * @var string
     */
    protected $extKey = 'template';

    /**
     * Export only records with this pid
     * @arg
     * @required
     * @var int
     */
    protected $pid = null;

    /**
     * The mask of the path within the extension the files will be
     * exportet to. Following variables are available:
     * ${scope} fce or page
     * ${title} title of the ds record
     *
     * Add a feature request/patch at forge.typo3.org if you need more
     *
     * @arg
     * @var string
     */
    protected $pathMask = 'ds/${scope}/${title}.xml';

    /**
     * Whether to include deleted ds
     * @arg
     * @var boolean
     */
    protected $includeDeletedRecords = false;

    /**
     * If the content should be appended to eventually existing files
     * @arg
     * @var boolean
     */
    protected $append = true;

    /**
     * If existing files should be overwritten
     * @arg
     * @var boolean
     */
    protected $overwrite = false;

    /**
     * If the tmplobj records should be updated after export
     * (set datastructure to file)
     * @arg
     * @var boolean
     */
    protected $update = true;

    /**
     * If the datastructure records should be deleted after export
     * @arg
     * @var boolean
     */
    protected $delete = true;

    /**
     * Renaming mode: 'camelCase', 'CamelCase' or 'under_scored'
     * @arg
     * @var string
     */
    protected $renameMode = 'camelCase';

    /**
     * @var t3lib_DB
     */
    protected $db;

    protected $columns = array(
        'setup' => 'config',
        'constants' => 'constants'
    );

    protected $rows = array();

    protected $rootlines = array();

    protected $templateFiles = array();

    protected $staticDirs = array();

    protected $updateColumns = array(
        'tx_templavoila_tmplobj' => 'datastructure',
        'pages' => array('tx_templavoila_ds', 'tx_templavoila_next_ds'),
        'tt_content' => 'tx_templavoila_ds'
    );

    public function tvstaticdsAction()
    {
        if (!t3lib_extMgm::isLoaded('templavoila')) {
            $this->_die('templavoila is not loaded');
        }
        $this->db = $GLOBALS['TYPO3_DB'];

        $extPath = PATH_typo3conf.'ext/'.$this->extKey.'/';
        $extRelPath = 'typo3conf/ext/'.$this->extKey.'/';

        if (!file_exists($extPath)) {
            t3lib_div::mkdir($extPath);
        }

        $paths = array();

        $where = 'pid='.$this->pid.($this->includeDeletedRecords ? '' : ' AND deleted = 0');
        $rows = $this->db->exec_SELECTgetRows('*', 'tx_templavoila_datastructure', $where);

        if (!count($rows)) {
            $this->_die('No records found on page '.$this->pid);
        }

        foreach ($rows as $row) {
            $file = $this->getPath($this->pathMask, array(
            	'scope' => $row['scope'] === '1' ? 'page' : 'fce',
                'title' => str_replace(array('/', '\\'), '-', $row['title'])
            ), $this->renameMode);
            $path = $extPath.$file;
            if (!file_exists(dirname($path))) {
                if (t3lib_div::mkdir_deep($extPath, dirname($file))) {
                    $this->_die('Could not make directory '.$path);
                }
            }
            if (!file_exists($path) || $this->overwrite) {
                file_put_contents($path, $row['dataprot']);
            }
            if ($this->update) {
                foreach ($this->updateColumns as $table => $columns) {
                    foreach ((array) $columns as $column) {
                        $this->_debug('Updating column '.$column.' on table '.$table);
                        $res = $this->db->exec_UPDATEquery(
                        	$table,
                        	$column.'='.$row['uid'],
                            array($column => $extRelPath.$file)
                        );
                        if (!$res) {
                            $this->_die('Could not update '.$table.'.'.$column.' for ds '.$row['uid']);
                        }
                    }
                }
            }
            if ($this->delete) {
                $this->db->exec_UPDATEquery(
                	'tx_templavoila_datastructure',
                	'uid='.$row['uid'],
                    array('deleted' => 1)
                );
            }
        }


        $conf = array('enable' => 1);
        foreach (array('page', 'fce') as $scope) {
            $file = $this->getPath($this->pathMask, array('scope' => $scope, 'title' => 'foo'), $this->renameMode);
            $conf['path_'.$scope] = $extRelPath.dirname($file).'/';
        }

        $this->writeExtConf('templavoila', array('staticDS.' => $conf));
    }
}