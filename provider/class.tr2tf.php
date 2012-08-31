<?php
class tx_t3build_provider_tr2tf extends tx_t3build_provider_abstract
{
    /**
     * The extension to which the files should be written
     * @arg
     * @var string
     */
    protected $extKey = 'template';

    /**
     * Whether to export as static template and add it to the record
     * or to export as single typoscript files and replace theyr source
     * with an appropriate INCLUDE_TYPOSCRIPT tag
     *
     * @arg
     * @var boolean
     */
    protected $static = true;

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
     * ${type}      - Type of TypoScript (setup or constants)
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
    protected $rootLineBegin = 2;

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

    protected $extensionPath;

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

    public function tr2tfAction()
    {
        $this->extensionPath = PATH_typo3conf.'ext/'.$this->extKey;
        if (!file_exists($this->extensionPath)) {
            t3lib_div::mkdir($this->extensionPath);
        }
        $this->db = $GLOBALS['TYPO3_DB'];

        if (!$this->includeSetup && !$this->includeConstants) {
            $this->_die('Nothing to export');
        }
        $columns = array();
        if ($this->includeSetup) {
            $columns[] = 'config';
        }
        if ($this->includeConstants) {
            $columns[] = 'constants';
        }
        $where = '(TRIM('.implode(") <> '' OR TRIM(", $columns).") <> '')";
        if (!$this->includeHidden) {
            $where .= ' AND hidden = 0';
        }
        if (!$this->includeDeleted) {
            $where .= ' AND deleted = 0';
        }
        if ($this->pid) {
            $where .= ' AND pid='.$this->pid;
        }
        $this->_debug($where);
        $rows = $this->db->exec_SELECTgetRows('*', 'sys_template', $where);
        foreach ($rows as $row) {
            $this->_collect((object) $row);
        }
        foreach ($this->rows as $row) {
            $this->_export($row);
        }
        if ($this->static) {
            $this->writeStatics();
        }
    }

    protected function writeStatics()
    {
        $file = $this->extensionPath.'/ext_tables.php';
        $content = file_exists($file) ? file_get_contents($file) : "<?php\n?>";
        $i = 0;
        foreach ($this->staticDirs as $uid => $dir) {
            $pattern = "/t3lib_extMgm\:\:addStaticFile\s*\(\s*[\"']$this->extKey[\"']\s*,\s*[\"']".preg_quote($dir, '/').'["\'][^\)]+\)\s*;\s*/';
            preg_replace($pattern, '', $content);
            $row = $this->rows[$uid];
            $title = $row->title;
            if (in_array($title, $this->replaceWithPageTitle)) {
                $rootline = $this->getRootline($row->pid);
                $title = array_pop($rootline);
            }
            $line = $i ? "\n" : "\n\n//Added by tx_t3build_provider_tr2tf\n";
            $line .= "t3lib_extMgm::addStaticFile('$this->extKey', '$dir', '$title'); ";
            $line .= "// Found in sys_template::$uid\n";
            $new = preg_replace('/\s*\?>$/', $line."?>", $content);
            $content = ($new == $content) ? rtrim($content).$line."?>" : $new;
            $i++;
        }
        if (!t3lib_div::writeFile($file, $content)) {
            $this->_die('Could not write to '.$file);
        }
    }

    protected function getRootline($id)
    {
        if (array_key_exists($id, $this->rootlines)) {
            return $this->rootlines[$id];
        }
        $where = 'uid = '.$id;
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
            $rootline = $page['pid'] ? $this->getRootline($page['pid']) : array();
            if (is_array($rootline)) {
                $rootline[] = $page['title'];
            }
        }
        $this->rootlines[$id] = $rootline;
        return $this->rootlines[$id];
    }

    protected function getPath($vars)
    {
        $replace = array();
        foreach ($vars as $key => $value) {
            $replace[] = '${'.$key.'}';
        }
        $path = str_replace($replace, $vars, $this->pathMask);
        if (preg_match('/\$\{([^\}]*)\}/', $path, $res)) {
            $this->_die('Unknown var "'.$res[1].'" in path mask');
        }
        $path = strtolower($path);
        $path = preg_replace('#[^a-z0-9/-_\.]+#i', ' ', $path);
        $path = preg_replace('#\s*/+\s*#', '/', $path);
        $parts = explode(' ', $path);
        if ($this->renameMode == 'underscore') {
            $path = implode('_', $parts);
        } else {
            $path = array_shift($parts);
            foreach ($parts as $part) {
                $path .= ucfirst($part);
            }
        }
        return $path;
    }

    protected function _collect($row)
    {
        $rootline = $this->getRootline($row->pid);
        if (!is_array($rootline)) {
            return;
        }
        $pageTitle = array_pop($rootline);
        for ($i = 0; $i < $this->rootLineBegin; $i++) {
            array_shift($rootline);
        }
        $rootline = implode('/', $rootline);
        $title = $row->title;
        if (in_array($title, $this->replaceWithPageTitle)) {
            $title = $pageTitle;
        }
        $siteTitle = $row->sitetitle ? $row->sitetitle : $title;
        $vars = array_merge(array(
                'uid' => $row->uid,
                'pid' => $row->pid
            ),
            compact('rootline', 'pageTitle', 'title', 'siteTitle')
        );
        $count = 0;
        foreach ($this->columns as $type => $column) {
            if (!strlen(trim($row->$column))) {
                continue;
            }
            $count++;
            $vars['type'] = $type;
            $vars['column'] = $column;
            $path = $this->getPath($vars);
            if (in_array($path, $this->templateFiles)) {
                $msg = 'Path "'.$path.'" already in use for template record '.$this->templateFiles['uid'].' - ';
                $msg .= 'try renaming one of those records';
                $this->_die('Found duplicate file on record '.$row->uid);
            }
            $this->templateFiles[$row->uid.'-'.$column] = $path;
            $this->_debug('Collected: '.$row->uid.'.'.$column.' -> '.$path);
        }
        if ($count) {
            $this->rows[$row->uid] = $row;
        }
    }

    protected function getContent($row, $column)
    {
        $file = $this->templateFiles[$row->uid.'-'.$column];
        $content = $row->$column;

        $pattern = '#^\s*<INCLUDE_TYPOSCRIPT\:\s+source\="\s*FILE\:\s*';
        $pattern .= '(EXT\:|typo3conf/ext/)'.preg_quote($this->extKey.'/'.$file, '#');
        $pattern .= '\s*"\s*>\s*$#m';

        $match = preg_match($pattern, $content);
        $path = t3lib_div::getFileAbsFileName('EXT:'.$this->extKey.'/'.$file);
        $exists = file_exists($path);

        if ($match || ($this->append && $exists)) {
            $fileContent .= $exists ? trim(file_get_contents($path), "\n") : '# (File not found)';
        } else {
            return $content;
        }

        if (!$match) {
            $content = rtrim($content)."\n\n";
            $content .= '# Content appended from sys_template::'.$row->uid.' on '.date('c').": \n";
            $content .= $fileContent;
            return $content;
        }

        $expanded = '# Content from EXT:'.$this->extKey.'/'.$file.' - expanded on '.date('c');
        $expanded .= "\n".$fileContent."\n";
        $expanded .= '# End of expanded content';

        preg_match_all('!^\s*(#.*?|/\*.*\*/)\s*$!ms', $content, $comments, PREG_OFFSET_CAPTURE);

        $new = '';
        $count = strlen($content);
        $last = 0;
        while ($last < $count) {
            $match = array_shift($matches[1]);
            $next = $match ? $match[1] : $count;
            $new .= preg_replace($pattern, $expanded, substr($content, $last, $next - $last));
            $last = $next;
            if ($match) {
                $new .= $match[0];
                $last .= strlen($match[0]);
            }
        }

        return $new;
    }

    protected function _export($row)
    {
        $update = array();
        $lastFile = '';
        foreach ($this->columns as $column) {
            $update[$column] = '';
            $file = $this->templateFiles[$row->uid.'-'.$column];
            $content = $this->getContent($row, $column);
            if (!$file || !$content) {
                continue;
            }
            $this->writeFile($lastFile = $file, $content, $row, $column);
            if (!$this->static) {
                $update[$column] = '<INCLUDE_TYPOSCRIPT: source="FILE:EXT:'.$this->extKey.'/'.$file.'">';
                $this->_debug('Writing typoscript include to '.$row->uid.'.'.$column);
            }
        }
        if ($this->static && $lastFile) {
            if (!array_key_exists($row->uid, $this->staticDirs)) {
                $addItem = 'EXT:'.$this->extKey.'/'.dirname($lastFile);
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

        $this->_debug('Data for record '.$row->uid.': ', $update);
        if ($this->updateRecords && !$this->db->exec_UPDATEquery('sys_template', 'uid='.$row->uid, $update)) {
            $this->_die('Could not update record '.$row->uid);
        }
    }

    protected function writeFile($file, $content, $row, $column)
    {
        $path = $this->extensionPath.'/'.$file;
        $dir = dirname($path);
        if (!file_exists($dir)) {
            $this->_debug('Creating directory '.$dir);
            if (t3lib_div::mkdir_deep($this->extensionPath.'/', dirname($file))) {
                $this->_die('Could not create directory '.$this->extensionPath.'/'.dirname($file));
            }
        }
        $this->_debug('Writing '.$row->uid.'.'.$column.' to '.$path);
        if (!t3lib_div::writeFile($path, $content)) {
            $this->_die('Could not write to '.$path);
        }
    }
}