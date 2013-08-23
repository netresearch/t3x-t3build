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
 * Provider to move typoscript setup and config from records to
 * files which can be named flexibly
 *
 * @package t3build
 * @author  Christian Opitz <christian.opitz@netresearch.de>
 * @license http://opensource.org/licenses/gpl-license GPLv2 or later
 */
class tx_t3build_provider_tr2tf extends tx_t3build_provider_abstract
{
    /**
     * The extension to which the files should be written
     *
     * @arg
     * @var string
     */
    protected $extKey = 'template';

    /**
     * The table to operate on (sys_template or pages)
     * @arg
     * @var string
     */
    protected $table = 'sys_template';

    /**
     * The mask of the path within the extension the files will be
     * exportet to. Following variables are available:
     * ${rootline}  - The rootline of the page starting at rootLineBegin
     *                page titles will be converted to valid file names
     * ${title}     - The title of the template record or parent page when
     *                template title is one of replaceWithPageTitle - will
     *                be empty when page is below rootLineBegin
     * ${pageTitle} - The title of the records parent page
     * ${siteTitle} - The website title when present or ${title}
     * ${type}      - Type of TypoScript (setup, config or constants)
     *
     * Add a feature request/patch at forge.typo3.org if you need more
     *
     * @arg
     * @var string
     */
    protected $pathMask = 'ts/${rootline}/${title}/${type}.txt';

    /**
     * When the template name is one of those it will be substituted with
     * with it's parent page title
     *
     * @arg
     * @var array
     */
    protected $replaceWithPageTitle = array('+EXT', '+ext');

    /**
     * On which level to begin with the rootline
     *
     * @arg
     * @var int
     */
    protected $rootlineBegin = 2;

    /**
     * Whether to include typoscript setup
     *
     * @arg
     * @var boolean
     */
    protected $includeSetup = true;

    /**
     * Whether to include typoscript setup
     *
     * @arg
     * @var boolean
     */
    protected $includeConstants = true;

    /**
     * Whether to include hidden template records
     * @arg
     * @var boolean
     */
    protected $includeHidden = false;

    /**
     * Whether to include deleted template records
     * @arg
     * @var boolean
     */
    protected $includeDeleted = false;

    /**
     * Whether to include template records from hidden pages
     * @arg
     * @var boolean
     */
    protected $includeHiddenPages = false;

    /**
     * Whether to include template records from deleted pages
     * @arg
     * @var boolean
     */
    protected $includeDeletedPages = false;

    /**
     * Export only records with this pid
     * @arg
     * @var int
     */
    protected $pid = null;

    /**
     * If the content should be appended to eventually existing files
     * @arg
     * @var boolean
     */
    protected $append = true;

    /**
     * If the records should be updated after export
     * @arg
     * @var boolean
     */
    protected $updateRecords = true;

    /**
     * Renaming mode: 'camelCase' or 'underscored'
     * @arg
     * @var string
     */
    protected $renameMode = 'camelCase';

    /**
     * Whether to export as static template and add it to the record
     * or to export as single typoscript files and replace theyr source
     * with an appropriate INCLUDE_TYPOSCRIPT tag
     *
     * @arg
     * @var boolean
     */
    protected $static = false;

    /**
     * When in static mode try to find the templates in "basedOn" and
     * replace them with the static parts
     * @arg
     * @var boolean
     */
    protected $basedOnToIncludeStaticFile = false;

    /**
     * @var string
     */
    protected $extensionPath;

    /**
     * @var t3lib_DB
     */
    protected $db;

    /**
     * @var array
     */
    protected $columns;

    /**
     * @var array
     */
    protected $tableColumns = array(
        'sys_template' => array(
            'setup' => 'config',
            'constants' => 'constants'
        ),
        'pages' => array(
            'config' => 'TSconfig'
        )
    );

    /**
     * @var array
     */
    protected $rows = array();

    /**
     * @var array
     */
    protected $rootlines = array();

    /**
     * @var array
     */
    protected $templateFiles = array();

    /**
     * @var array
     */
    protected $staticDirs = array();

    /**
     * The action to move the typoscript from records to files
     *
     * @return void
     */
    public function tr2tfAction()
    {
        $this->extensionPath = PATH_typo3conf . 'ext/' . $this->extKey;

        if (!file_exists($this->extensionPath)) {
            t3lib_div::mkdir($this->extensionPath);
        }

        $this->db = $GLOBALS['TYPO3_DB'];

        if (!$this->includeSetup && !$this->includeConstants) {
            $this->_die('Nothing to export');
        }

        if (!array_key_exists($this->table, $this->tableColumns)) {
            $this->_die('Table ' . $this->table . ' is not configured');
        }

        if (!$this->table != 'sys_template' && $this->staticDirs) {
            $this->_die('Static is only supported for table sys_template');
        }

        $this->columns = $this->tableColumns[$this->table];

        if (!$this->includeSetup && array_key_exists('setup', $this->columns)) {
            unset($this->columns['setup']);
        }

        if (!$this->includeConstants
            && array_key_exists('constants', $this->columns)
        ) {
            unset($this->columns['constants']);
        }

        $where = '(TRIM(' . implode(") <> '' OR TRIM(", $this->columns) . ") <> '')";

        if (!$this->includeHidden) {
            $where .= ' AND hidden = 0';
        }

        if (!$this->includeDeleted) {
            $where .= ' AND deleted = 0';
        }

        if ($this->pid) {
            $where .= ' AND pid=' . $this->pid;
        }

        $this->_debug($where);
        $rows = $this->db->exec_SELECTgetRows('*', $this->table, $where);

        foreach ($rows as $row) {
            $this->_collect((object) $row);
        }

        foreach ($this->rows as $row) {
            $this->_export($row);
        }

        if ($this->static) {
            if ($this->basedOnToIncludeStaticFile) {
                $this->basedOn2IncludeStaticFile();
            }

            $this->writeStatics();
        }
    }

    /**
     * Write the static files to {extensionPath}/ext_tables.php
     *
     * @return void
     */
    protected function writeStatics()
    {
        $file = $this->extensionPath . '/ext_tables.php';
        $content = file_exists($file) ? file_get_contents($file) : "<?php\n?>";

        $i = 0;
        foreach ($this->staticDirs as $uid => $dir) {
            $row = $this->rows[$uid];
            $title = $row->title;

            if (in_array($title, $this->replaceWithPageTitle)) {
                $rootline = $this->getRootline($row->pid);
                $title = array_pop($rootline);
            }

            $line = $i ? "\n" : "\n\n//Added by tx_t3build_provider_tr2tf\n"
                . "t3lib_extMgm::addStaticFile('$this->extKey', '$dir', '$title'); "
                . "// Found in sys_template::$uid\n";
            $new = preg_replace('/\s*\?>$/', $line . "?>", $content);
            $content = ($new == $content) ? rtrim($content) . $line . "?>" : $new;
            $i++;
        }

        if (!t3lib_div::writeFile($file, $content)) {
            $this->_die('Could not write to ' . $file);
        }
    }

    /**
     * Replace templates in baseOn with static
     *
     * @return void
     */
    protected function basedOn2IncludeStaticFile()
    {
        foreach ($this->rows as $row) {
            $baseUids = t3lib_div::intExplode(',', $row->basedOn, true);

            if (!count($baseUids)) {
                $this->_debug('No basedOn-Template found for template ' . $row->uid);
                continue;
            }

            $rest = array();
            $includeStatics = array();
            foreach ($baseUids as $uid) {
                if (!array_key_exists($uid, $this->staticDirs)) {
                    $rest[] = $uid;
                    continue;
                }

                $includeStatics[$uid] = 'EXT:' . $this->extKey . '/'
                    . $this->staticDirs[$uid];
            }

            if (count($includeStatics)) {
                $file = $this->staticDirs[$row->uid] . '/include_static_file.txt';
                $content = implode(',', $includeStatics);
                $this->writeFile($file, $content, $row, 'basedOn');
            }

            if ($this->updateRecords) {
                $rest = implode(',', $rest);
                $this->_debug(
                    'Setting basedOn to "' . $rest . '" on template ' . $row->uid
                );
                $res = $this->db->exec_UPDATEquery(
                    $this->table, 'uid=' . $row->uid, array('basedOn' => $rest)
                );

                if (!$res) {
                    $this->_die('Could not update record ' . $row->uid);
                }
            }
        }
    }

    /**
     * Get the rootline for the given uid
     *
     * @param integer $id The uid of the page
     *
     * @return array|null An array with the record for the rootline, null on error
     */
    protected function getRootline($id)
    {
        if (array_key_exists($id, $this->rootlines)) {
            return $this->rootlines[$id];
        }

        if ($id) {
            $where = 'uid = ' . $id;

            if (!$this->includeHiddenPages) {
                $where .= ' AND hidden = 0';
            }

            if (!$this->includeDeletedPages) {
                $where .= ' AND deleted = 0';
            }

            $page = $this->db->exec_SELECTgetSingleRow('*', 'pages', $where);

            if (!$page) {
                $rootline = null;
            } else {
                $rootline = $this->getRootline($page['pid']);

                if (is_array($rootline)) {
                    $rootline[] = $page['title'];
                }
            }
        } else {
            $rootline = array();
        }

        $this->rootlines[$id] = $rootline;

        return $this->rootlines[$id];
    }

    /**
     * Adds the given row to the objects rows variable and fetches the path given in
     * the path mask and adds it to the array of template file paths.
     *
     * @param object $row Object containing the template information
     *
     * @return void
     */
    protected function _collect($row)
    {
        // collect variables for compact
        $rootline = $this->getRootline($row->pid);

        if (!is_array($rootline)) {
            return;
        }

        $pageTitle = array_pop($rootline);

        for ($i = 0; $i < $this->rootlineBegin; $i++) {
            array_shift($rootline);
        }

        $rootline = implode('/', $rootline);
        $title = $row->title;

        if (in_array($title, $this->replaceWithPageTitle)) {
            $title = $pageTitle;
        }

        $siteTitle = $row->sitetitle ? $row->sitetitle : $title;

        $vars = array_merge(
            array(
                'uid' => $row->uid,
                'pid' => $row->pid
            ),
            compact('rootline', 'pageTitle', 'title', 'siteTitle')
        );

        foreach ($this->columns as $type => $column) {
            $vars['type'] = $type;
            $vars['column'] = $column;
            $path = $this->getPath($this->pathMask, $vars, $this->renameMode);

            if (in_array($path, $this->templateFiles)) {
                $msg = 'Path "' . $path . '" already in use for template record '
                    . $this->templateFiles['uid'] . ' - '
                    . 'try renaming one of those records';
                $this->_die('Found duplicate file on record ' . $row->uid);
            }

            $this->templateFiles[$row->uid . '-' . $column] = $path;
            $this->_debug('Collected: ' . $row->uid . '.' . $column.' -> ' . $path);
        }

        $this->rows[$row->uid] = $row;
    }

    /**
     * If the append flag is set, the file exists and the content does not contain a
     * INCLUDE_TYPOSCRIPT, the content of the sys template is appended.
     * If the content contains INCLUDE_TYPOSCRIPT, it is replaced with the content of
     * the previously included typoscript file.
     * In all other cases the content of $column in $row is returned.
     *
     * @param object $row    Object containing the template information
     * @param string $column The column where the content is lying
     *
     * @return string The typoscript content
     */
    protected function getContent($row, $column)
    {
        $file = $this->templateFiles[$row->uid . '-' . $column];
        $content = $row->$column;

        $pattern = '#^\s*<INCLUDE_TYPOSCRIPT\:\s+source\="\s*FILE\:\s*'
            . '(EXT\:|typo3conf/ext/)' . preg_quote($this->extKey . '/' . $file, '#')
            . '\s*"\s*>\s*$#m';

        $match = preg_match($pattern, $content);
        $path = t3lib_div::getFileAbsFileName('EXT:' . $this->extKey . '/' . $file);
        $exists = file_exists($path);

        if ($match || ($this->append && $exists)) {
            $fileContent .= $exists
                ? trim(file_get_contents($path), "\n") : '# (File not found)';
        } else {
            return $content;
        }

        if (!$match) {
            return rtrim($content) . "\n\n"
                . '# Content appended from sys_template::' . $row->uid . ' on '
                . date('c').": \n" . $fileContent;
        }

        $expanded = '# Content from EXT:' . $this->extKey . '/' . $file
            . ' - expanded on ' . date('c') . "\n" . $fileContent . "\n"
            . '# End of expanded content';

        // get all comments in the content
        preg_match_all(
            '!^\s*(#.*?|/\*.*\*/)\s*$!ms', $content, $comments, PREG_OFFSET_CAPTURE
        );

        // replace the typoscript includes with the content of the typoscript files
        $new = '';
        $count = strlen($content);
        $last = 0;
        while ($last < $count) {
            $match = $comments[1] ? array_shift($comments[1]) : null;
            $next  = $match ? $match[1] : $count;
            $new  .= preg_replace(
                $pattern, $expanded, substr($content, $last, $next - $last)
            );
            $last = $next;

            if ($match) {
                $new  .= $match[0];
                $last .= strlen($match[0]);
            }
        }

        return $new;
    }

    /**
     * Get the content and write it to a file. If the static flag is set, add an
     * entry to the staticDirs list.
     *
     * @param object $row Object containing the template information
     *
     * @return void
     */
    protected function _export($row)
    {
        $update = array();
        $lastFile = '';
        foreach ($this->columns as $column) {
            $update[$column] = '';
            $file = $this->templateFiles[$row->uid . '-' . $column];
            $content = $this->getContent($row, $column);

            if ($file) {
                $lastFile = $file;
            }

            if (!$file || !trim($content)) {
                continue;
            }

            $this->writeFile($file, $content, $row, $column);

            if (!$this->static) {
                $update[$column] = '<INCLUDE_TYPOSCRIPT: source="FILE:EXT:'
                    . $this->extKey . '/' . $file . '">';
                $this->_debug(
                    'Writing typoscript include to ' . $row->uid . '.' . $column
                );
            }
        }

        if ($this->static && $lastFile) {
            if (!array_key_exists($row->uid, $this->staticDirs)) {
                $addItem = 'EXT:' . $this->extKey . '/' . dirname($lastFile);
                $items = explode(',', $row->include_static_file);

                foreach ($items as $i => $item) {
                    $item = trim($item, '/ ');

                    if (!$item || $item == $addItem) {
                        unset($items[$i]);
                        continue;
                    }
                }

                $items[] = $addItem;
                $update['include_static_file'] = implode(',', $items);
                $this->staticDirs[$row->uid] = dirname($lastFile);
            }
        }

        $this->_debug('Data for record ' . $row->uid . ': ', $update);

        if ($this->updateRecords
            && !$this->db->exec_UPDATEquery(
                $this->table, 'uid=' . $row->uid, $update
            )
        ) {
            $this->_die('Could not update record ' . $row->uid);
        }
    }

    /**
     * Write the provided content to the given file. If the directory does not exist,
     * it will be created.
     *
     * @param string $file    The file where to write the content to
     * @param string $content The content for the file
     * @param object $row     Object containing the template information (only used
     *                        for debug purposes)
     * @param string $column  The column where the content is lying (only used for
     *                        debug purposes)
     *
     * @return void
     */
    protected function writeFile($file, $content, $row, $column)
    {
        $path = $this->extensionPath . '/' . $file;
        $dir = dirname($path);

        if (!file_exists($dir)) {
            $this->_debug('Creating directory ' . $dir);

            if (t3lib_div::mkdir_deep($this->extensionPath . '/', dirname($file))) {
                $this->_die(
                    'Could not create directory ' . $this->extensionPath . '/'
                    . dirname($file)
                );
            }
        }

        $this->_debug('Writing ' . $row->uid . '.' . $column . ' to ' . $path);

        if (!t3lib_div::writeFile($path, $content)) {
            $this->_die('Could not write to ' . $path);
        }
    }
}
?>
