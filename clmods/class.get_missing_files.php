<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 1999-2011 Kasper Skårhøj (kasperYYYY@typo3.com)
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
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

require_once t3lib_extMgm::extPath('lowlevel').'clmods/class.missing_files.php';

/**
 * Override of tx_lowlevel_missing_files to download files from other urls when they
 * are not present
 *
 * @package    TYPO3
 * @subpackage tx_t3build
 * @author     Christian Opitz <christian.opitz@netresearch.de>
 * @license    http://opensource.org/licenses/gpl-license GPLv2 or later
 */
class tx_t3build_get_missing_files extends tx_lowlevel_missing_files
{
    /**
     * Used in cli_main()
     *
     * @var boolean
     */
    var $checkRefIndex = TRUE;

    /**
     * Add cli options and help
     */
    public function __construct()
    {
        parent::__construct();
        $this->cli_options[] = array('--url URL', 'When URLs are provided, this '
            . 'script will try to download missing files from there (Multiple URLs '
            . 'can be provided as comma separated list of by using multiple args)');

        $this->cli_help['name'] = 'get_missing_files -- Find all file references '
            . 'from records pointing to a missing (non-existing) files and try to '
            . 'download them.';
        $description = "\n" . 'Assumptions:' . "\n"
            . '- a perfect integrity of the reference index table (always update '
            . 'the reference index table before using this tool!)' . "\n"
            . '- relevant soft reference parsers applied everywhere file references '
            . 'are used inline' . "\n\n"
            . 'Files may be missing for these reasons (except software bugs):' . "\n"
            . '- you have a not uptodate working copy' . "\n"
            . '- someone manually deleted the file inside fileadmin/ or another user'
            . ' maintained folder. If the reference was a soft reference (opposite '
            . 'to a TCEmain managed file relation from "group" type fields), '
            . 'technically it is not an error although it might be a mistake that '
            . 'someone did so.' . "\n"
            . '- someone manually deleted the file inside the uploads/ folder '
            . '(typically containing managed files) which is an error since no user '
            . 'interaction should take place there.' . "\n\n"
            . 'Automatic Repair of Errors:' . "\n"
            . '- Download the files from the URLs you provided' . "\n\n"
            . 'Manual repair suggestions:' . "\n"
            . '- Managed files: You might be able to locate the file and re-insert '
            . 'it in the correct location. However, no automatic fix can do that '
            . 'for you.' . "\n"
            . '- Soft References: You should investigate each case and edit the '
            . 'content accordingly. A soft reference to a file could be in an HTML '
            . 'image tag (for example <img src="missing_file.jpg" />) and you would '
            . 'have to either remove the whole tag, change the filename or '
            . 're-create the missing file.' . "\n";
        $this->cli_help['description'] = trim($description);

        $this->cli_help['examples'] = '/.../cli_dispatch.phpsh lowlevel_cleaner '
            . 'get_missing_files -r --refindex=update --url=www.example.com '
            . '--AUTOFIX' . "\n" . 'Will show you missing files, ask you to autofix '
            . 'and when you enter "Yes" try to download the files from '
            . 'www.example.com.';
    }

    /* (non-PHPdoc)
     * @see tx_lowlevel_missing_files::main_autoFix()
     */
    function main_autoFix($resultArray)
    {
        $url = implode(',', (array) $this->cli_args['--url']);
        $urls = $url ? explode(',', $url) : array();
        $urlCount = count($urls);

        foreach ($urls as $url) {
            if (!preg_match('#^([a-z]+)://#i', $url, $match)) {
                $scheme = 'http';
                $url = $scheme.'://'.$url;
            } else {
                $scheme = strtolower($match[1]);
            }

            $url = rtrim($url, '/');

            foreach (array('managedFilesMissing', 'softrefFilesMissing') as $key) {
                foreach ($resultArray[$key] as $file => $dbStuff) {
                    if (!$urlCount) {
                        $this->cli_echo('[ERROR]: No urls provided!', true);
                        exit;
                    }

                    $filePath = t3lib_div::getFileAbsFileName($file);
                    $dir = dirname($filePath);

                    if (!file_exists($dir)) {
                        t3lib_div::mkdir_deep(
                            PATH_site, substr($dir, strlen(PATH_site))
                        );
                    }

                    $fileUrl = $url;

                    foreach (explode('/', $file) as $part) {
                        $fileUrl .= '/'.urlencode($part);
                    }

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_HEADER, 0);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_URL, $fileUrl);
                    $this->cli_echo('Downloading '.$fileUrl);

                    if ($scheme == 'http' || $scheme == 'https') {
                        $realUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                        if(strpos(array_shift(@get_headers($realUrl)), '404')) {
                            $this->cli_echo(' - 404 Not found'.PHP_EOL);
                            continue;
                        }
                    }

                    $out = fopen($filePath, 'wb');
                    if (!$out) {
                        $this->cli_echo(
                            ' - [ERROR]: Could not open '.$filePath.' for writing'
                        );
                        continue;
                    }

                    curl_setopt($ch, CURLOPT_FILE, $out);
                    curl_exec($ch);
                    $error = curl_error($ch);

                    if ($error) {
                        $this->cli_echo(' - [ERROR]:'.$error.PHP_EOL);
                    } else {
                        $this->cli_echo(' - successfull'.PHP_EOL);
                        unset($resultArray[$key][$file]);
                    }

                    curl_close($ch);
                }
            }
        }
    }
}
?>
