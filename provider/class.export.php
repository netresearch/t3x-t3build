<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009 AOE media GmbH <dev@aoemedia.de>
*  All rights reserved
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once PATH_t3lib . 'class.t3lib_install.php';
require_once t3lib_extMgm::extPath('t3build').'provider/class.abstract.php';

/**
 * Command that exports the database and all files in the configured
 * directories to an .tar.gz archive.
 * As it really exports all files found in those directories (regardless
 * if they are still referenced) you might consider running some lowlevel
 * cleaning commands before the export. Luckily this script provides
 * an easy way for you to do this: Just add -c and this script will invoke
 * the cleaners get_missing_files and lost_files for you.
 * You can override the cleaner modules to invoke and you can also pass
 * them any options like for instance:
 * -clean-CLEANER_MODULE-v or --clean-CLEANER_MODULE-showhowto
 * (The options -r and --AUTOFIX are forced and you can use --clean-yes
 * to force the --YES parameter on each cleaner module)
 *
 * @package t3build
 * @author Christian Opitz <co@netzelf.de>
 */
class tx_t3build_provider_export extends tx_t3build_provider_abstract
{
    /**
     * The file to which to export (currently only relative to PATH_site)
     * @arg
     * @var string
     */
    protected $file = 'typo3conf/t3build.tar.gz';

    /**
     * Temporary directory in which to operate
     * @arg
     * @var string
     */
    protected $tempDir = 'typo3temp/t3build';

    /**
     * Whether to export files
     * @arg
     * @var boolean
     */
    protected $files = true;

    /**
     * Whether to export the database
     * @arg
     * @var boolean
     */
    protected $database = true;

    /**
     * Execute several lowlevel cleanup commands before starting
     * the export
     * @arg
     * @var boolean
     */
    protected $clean = false;

    /**
     * Arguments to pass to the clean commands (--clean-command-arg)
     * @mask --clean-*
     * @arg
     * @var array
     */
    protected $cleanArgs = array();

    /**
     * The clean commands (see lowlevel cleaner) to execute.
     * ("all" executes all registered commands but those configured in
     * skip-clean-commands)
     * @arg
     * @var string
     */
    protected $cleanCommands = 'get_missing_files,lost_files';

    /**
     * Cleaner commands to skip when clean-commands is set to "all"
     * @arg
     * @var string
     */
    protected $skipCleanCommands = 'versions,syslog,deleted';

    /**
     * Whether to answer the cleaner autofix questions with yes
     * @arg
     * @var boolean
     */
    protected $cleanYes = false;

    /**
     * Whether to tell the cleaners to update the refindex before analysis
     * @arg
     * @var boolean
     */
    protected $updateRefindex = true;

    /**
     * Export the checksums with the files
     * @arg
     * @var boolean
     */
    protected $exportChecksums = false;

    /**
     * Directories to export
     * @arg
     * @var string
     */
    protected $directories = 'fileadmin,uploads';

    /**
     * Tables to ignore (wildcard permitted)
     * @arg
     * @var string
     */
    protected $ignoreTables = 'cache_*,static_*,index_*';

    /**
     * The path to the temporary tar file
     * @var string
     */
    protected $tempTarFile;

    /**
     * Path to the temporary info dir (.t3build)
     * @var string
     */
    protected $tempInfoDir;

    /**
     * The SimpleXMLElement which holds all information
     * to write to the info file
     * @var SimpleXMLElement
     */
    protected $info;

    /**
     * Instance of PEAR/Archive_Tar
     * @var Archive_Tar
     */
    protected $tar;

	/**
	 * The only action to call
	 */
	public function exportAction()
	{
        if (!$this->files && !$this->database) {
            $this->_die('No files and no database? Don\'t have anything more to export!');
        }
        $this->setup();
        if ($this->clean) {
            $this->clean();
        }
        if ($this->database) {
            $this->exportDatabase();
        }
        if ($this->files) {
            $this->exportFiles();
        }
        $this->finish();
	}

	/**
	 * Setup (clean/create temp dirs), instantiae Archive_Tar, create info
	 */
	protected function setup()
	{
	    $tempDir = t3lib_div::getFileAbsFileName($this->tempDir);
	    $this->_debug('tempDir is '.$tempDir);
	    if (file_exists($tempDir)) {
	        $this->_debug('Cleaning tempDir');
	        t3lib_div::rmdir($tempDir, true);
	    } else {
	        $this->_debug('Creating tempDir');
	    }
        t3lib_div::mkdir_deep(PATH_site, $this->tempDir);
        $this->tempDir = realpath($tempDir);
        $this->tempTarFile = $this->tempDir.DIRECTORY_SEPARATOR.basename($this->file);
        t3lib_div::mkdir($this->tempInfoDir = $this->tempDir.DIRECTORY_SEPARATOR.'.t3build');

        @include_once 'Archive/Tar.php';
        if (!class_exists('Archive_Tar') || Archive_Tar instanceof PEAR) {
            set_include_path(t3lib_extMgm::extPath('t3build').'/contrib/PEAR'.PATH_SEPARATOR.get_include_path());
            require_once 'Archive/Tar.php';
        }
        $this->tar = new Archive_Tar($this->tempTarFile, 'gz');

        $this->info = new SimpleXMLElement('<t3build version="1.0"></t3build>');
        $info = $this->info->addChild('info');
        $info->addChild('date', date('c'));
        $info->addChild('computer', $_SERVER['COMPUTERNAME']);
        $info->addChild('user', $_SERVER['USERNAME']);
        $info->addChild('os', $_SERVER['OS']);
	}

	/**
	 * Invoke the configured cleaner modules
	 */
	protected function clean()
	{
	    require_once(t3lib_extMgm::extPath('lowlevel').'class.tx_lowlevel_cleaner_core.php');
        require(PATH_typo3.'template.php');

        if ($this->cleanCommands == 'all') {
            $availableCommands = array_keys((array) $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lowlevel']['cleanerModules']);
            $commands = array_diff($availableCommands, explode(',', $this->skipCleanCommands));
        } else {
            $commands = explode(',', $this->cleanCommands);
        }

        $cleanArgs = $this->cleanArgs;
        foreach ($commands as $command) {
            $args = array(
                $this->cliArgs['_DEFAULT'][0],
                $command,
                '-r',
                '--AUTOFIX',
            );
            if ($this->updateRefindex) {
                $args[] = '--refindex=update';
            }
            if ($this->cleanYes) {
                $args[] = '--YES';
            }
            $l = strlen($command) + 1;
            foreach ($this->cleanArgs as $cleanArg => $cleanArgVal) {
                $pos = strpos($cleanArg, $command.'-');
                if ($pos == 1 || $pos == 2) {
                    $cleanArgTarget = substr($cleanArg, 0, $pos);
                    $cleanArgTarget .= substr($cleanArg, $l + $pos);
                    foreach ($cleanArgVal as $value) {
                        $args[] = $cleanArgTarget.'='.$value;
                    }
                    unset($cleanArgs[$cleanArg]);
                }
            }
            $_SERVER['argv'] = $args;
            /* @var $cleanerObj tx_lowlevel_cleaner_core */
            $cleanerObj = t3lib_div::makeInstance('tx_lowlevel_cleaner_core');
            $cleanerObj->cli_main($args);
        }
	}

	/**
	 * Write info.xml, move tar to target and remove temp dir
	 */
	protected function finish()
	{
	    $infoFile = $this->tempInfoDir.DIRECTORY_SEPARATOR.'info.xml';
	    $this->info->asXML($infoFile);
	    $this->addToTar($infoFile);
	    $this->tar->_close();

	    $target = t3lib_div::getFileAbsFileName($this->file);
	    if (file_exists($target)) {
	        $this->_debug('Backing up existing archive: '.$target);
	        rename($target, $target.'.bak');
	    }
	    $this->_debug('Moving archive from '.$this->tempTarFile.' to '.$target);
	    if (!rename($this->tempTarFile, $target)) {
	        $this->_echo('WARNING: Could not move archive');
	    }
	    if (file_exists($target.'.bak')) {
	        @unlink($target.'.bak');
	    }

	    $this->_debug('Removing temp dir '.$this->tempDir);
	    if (!t3lib_div::rmdir($this->tempDir, true)) {
	        $this->_echo('WARNING: Could not remove temp dir');
	    }
	}

	/**
	 * Add a file to the archive
	 *
	 * @param string $file
	 * @param string $stripDir
	 */
	protected function addToTar($file, $stripDir = null)
	{
	    if (!$stripDir && strpos($file, $this->tempDir) === 0) {
	        $stripDir = $this->tempDir;
	    } else {
	        $stripDir = ($stripDir) ? t3lib_div::getFileAbsFileName($stripDir) : PATH_site;
	    }
	    $this->tar->addModify(array($file), '', $stripDir);
	}

	/**
	 * Export the database
	 */
	protected function exportDatabase()
	{
	    /* @var $TYPO3_DB t3lib_db */
	    global $TYPO3_DB;
	    $db = TYPO3_db;
	    $target = $this->tempInfoDir.DIRECTORY_SEPARATOR.($file = 'database.sql');

	    if (!t3lib_exec::checkCommand('mysqldump')) {
	        $this->_die('mysqldump could not be found');
	    }
        $args = array(
            '-h '.TYPO3_db_host,
            '-u '.TYPO3_db_username,
        );
        if (TYPO3_db_password) {
            $args[] = '-p'.TYPO3_db_password;
        }
        if ($this->debug) {
            $args[] = '-v';
        }
        $args[] = $db;
        if ($this->ignoreTables) {
            $ignoreTables = explode(',', $this->ignoreTables);
            $availableTables = array_keys($TYPO3_DB->admin_get_tables());
            foreach ($ignoreTables as $ignoreTable) {
                $pattern = strpos($ignoreTable, '*') !== false ? '/^'.str_replace('*', '.*', $ignoreTable).'$/' : null;
                foreach ($availableTables as $table) {
                    if ($pattern && preg_match($pattern, $table) || $table == $ignoreTable) {
                        $this->_debug('Ignore '.$table);
                        $args[] = '--ignore-table='.$db.'.'.$table;
                        $ignoreTables[] = $table;
                    }
                }
            }
        }

        // Finally execute the whole stuff:
        echo 'Exporting database ';
        $exec = t3lib_exec::getCommand('mysqldump');
        $command = $exec.' '.implode(' ', $args).' > "'.$target.'"';
        $this->_debug($command);
        exec($command, $output, $retVal);
        foreach ($output as $line) {
            $this->_echo($line);
        }
        if ($retVal) {
            $this->_die('- mysqldump returned with an error - aborting');
        } else {
            $this->_echo('- finished');
        }

        $this->_debug('Writing dump to archive');
        $this->addToTar($target);

        $dbInfo = $this->info->addChild('database');
        $fileInfo = $dbInfo->addChild('file', basename($this->tempInfoDir).'/'.$file);
        $fileInfo['mdate'] = $fileInfo['cdate'] = date('c');
	}

	/**
	 * Export the files from the configured directories
	 */
	protected function exportFiles()
	{
	    $dirs = explode(',', $this->directories);
	    $files = array();
	    $infoFiles = $this->info->addChild('files');
	    $l = strlen(realpath(PATH_site));

	    $this->_debug('Collecting files');

	    foreach ($dirs as $dir) {
	        $directory = new RecursiveDirectoryIterator(t3lib_div::getFileAbsFileName($dir));
	        $iterator = new RecursiveIteratorIterator($directory);
	        foreach ($iterator as $file) {
	            /* @var $file SplFileInfo */
	            if ($file->isDir()) {
	                continue;
	            }
	            $files[] = (string) $file;
	            $infoFile = $infoFiles->addChild('file', str_replace('\\', '/', substr((string) $file, $l+1)));
	            $infoFile['mdate'] = date('c', $file->getMTime());
	            $infoFile['cdate'] = date('c', $file->getCTime());
                if ($this->exportChecksums) {
                    $infoFile['checksum'] = md5_file((string) $file);
                }
	        }
	    }
        $msg = 'Archiving files';
        $count = count($files);
        $strlen = strlen((string) $count);
        echo $msg."\r";
	    foreach ($files as $i => $file) {
	        echo $msg.': '.@str_repeat(' ', $strlen - strlen($i+1)).($i+1).'/'.$count."\r";
	        $this->addToTar($file);
	    }
	    $this->_echo($msg.' - finished'.@str_repeat(' ', $strlen+$strlen-7));
	}
}
