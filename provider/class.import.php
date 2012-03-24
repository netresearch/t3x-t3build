<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009 AOE media GmbH <dev@aoemedia.de>
*  All rights reserved
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

t3lib_div::requireOnce(PATH_t3lib . 'class.t3lib_install.php');

/**
 * Command that imports the database and all files in the configured
 * directories to an .tar.gz archive.
 * As it really imports all files found in those directories (regardless
 * if they are still referenced) you might consider running some lowlevel
 * cleaning commands before the import. Luckily this script provides
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
class tx_t3build_provider_import extends tx_t3build_provider_abstract
{
    /**
     * The file to which to import
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
     * Whether to import files
     * @arg
     * @var boolean
     */
    protected $files = true;

    /**
     * Whether to import the database
     * @arg
     * @var boolean
     */
    protected $database = true;

    /**
     * Export the checksums with the files
     * @arg
     * @var boolean
     */
    protected $checkChecksums = false;

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
	public function importAction()
	{
        if (!$this->files && !$this->database) {
            $this->_die('No files and no database? Don\'t have anything more to import!');
        }
        $this->setup();
        if ($this->database) {
            $this->importDatabase();
        }
        if ($this->files) {
            $this->importFiles();
        }
        $this->finish();
	}

	/**
	 * Setup (clean/create temp dirs), instantiae Archive_Tar, create info
	 */
	protected function setup()
	{
	    $tempDir =  t3lib_div::getFileAbsFileName($this->tempDir);
	    $this->_debug('tempDir is '.$tempDir);
	    if (file_exists($tempDir)) {
	        $this->_debug('Cleaning tempDir');
	        t3lib_div::rmdir($tempDir, true);
	    } else {
	        $this->_debug('Creating tempDir');
	    }
        t3lib_div::mkdir_deep(PATH_site, $this->tempDir);
        $this->tempDir = realpath($tempDir);

        @include_once 'Archive/Tar.php';
        if (!class_exists('Archive_Tar') || Archive_Tar instanceof PEAR) {
            set_include_path(t3lib_extMgm::extPath('t3build').'/contrib/PEAR'.PATH_SEPARATOR.get_include_path());
            require_once 'Archive/Tar.php';
        }
        $file = t3lib_div::getFileAbsFileName($this->file);
        if (!$file) {
            $file = realpath($file);
        }
        $this->tar = new Archive_Tar($file);

        if (!$this->tar->extractList(array('.t3build/info.xml'), $this->tempDir)) {
            $this->_die($this->tar->error_object);
        }
        $this->info = simplexml_load_file($this->tempDir.DIRECTORY_SEPARATOR.'.t3build'.DIRECTORY_SEPARATOR.'info.xml');
	}

	/**
	 * Remove temp dir
	 */
	protected function finish()
	{
	    t3lib_div::rmdir($this->tempDir, true);
	}

	/**
	 * Import the database dumps found in the info
	 */
	protected function importDatabase()
	{
		/* @var $TYPO3_DB t3lib_db */
	    global $TYPO3_DB;

	    if (!t3lib_exec::checkCommand('mysql')) {
	        $this->_die('mysqldump could not be found');
	    }

	    $baseCommand = t3lib_exec::getCommand('mysql');
	    $baseCommand .= ' -h '.TYPO3_db_host;
	    $baseCommand .= ' -u '.TYPO3_db_username;
	    if (TYPO3_db_password) {
	        $baseCommand .= ' -p'.TYPO3_db_password;
	    }
	    if ($this->debug) {
	        $baseCommand .= ' -v';
	    }
	    $baseCommand .= ' '.TYPO3_db;

	    echo 'Importing database ';

	    foreach ($this->info->database->file as $fileChild) {
	        $file = (string) $fileChild;
	        $source = $this->tempDir.DIRECTORY_SEPARATOR.$file;
	        $this->_debug('Extracting '.$file);
	        if (!$this->tar->extractList(array($file), $this->tempDir)) {
	            $this->_die($this->tar->error_object);
	        }
	        $command = $baseCommand.' < '.$source;
	        $this->_debug($command);
            exec($command, $output = array(), $retVal);
            foreach ($output as $line) {
                $this->_echo($line);
            }
            if ($retVal) {
                $this->_die('mysql returned with an error - aborting');
            }
	    }

        $this->_echo('- finished');
	}

	/**
	 * Import the files found in the info
	 */
	protected function importFiles()
	{
	    $path = PATH_site;
	    $files = array();
	    foreach ($this->info->files->file as $fileChild) {
	        $files[] = (string) $fileChild;
	    }
	    if (count($files)) {
	        $this->_echo('No files found');
	    }
	    $this->_debug('Importing files', $files);
	    echo 'Importing files';
	    if (!$this->tar->extractList($files, PATH_site)) {
	        $this->_die(PHP_EOL.$this->tar->error_object);
	    }
	    $this->_echo(' - finished');
	}
}
