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
 * Provider to copy templavoila datastructure to files and link
 * them with the template objects
 *
 * @package t3build
 * @author  Christian Opitz <christian.opitz@netresearch.de>
 * @license http://opensource.org/licenses/gpl-license GPLv2 or later
 */
class tx_t3build_provider_history extends tx_t3build_provider_abstract
{
    /**
     * @var string
     */
    const LOG_CHECKPOINT_TEXT = '[t3build] Pulled history';

    /**
     * The remote server database host
     *
     * @arg
     * @required
     * @var string
     */
    protected $host = 'localhost';

    /**
     * The remote server database user
     *
     * @arg
     * @required
     * @var string
     */
    protected $user = null;

    /**
     * The remote server database password
     *
     * @arg
     * @required
     * @var string
     */
    protected $pass = null;

    /**
     * The remote server database port
     *
     * @arg
     * @var integer
     */
    protected $port;

    /**
     * The remote server database name
     *
     * @arg
     * @required
     * @var string
     */
    protected $database = null;

    /**
     * The remote server database port
     *
     * @arg
     * @var integer
     */
    protected $driver = 'mysql';

    /**
     * If the script should search the last date that the
     * history was in sync from above or from below
     * (above is faster when there were minor updates in
     * the remote db)
     *
     * @arg
     * @var boolean
     */
    protected $searchFromAbove = true;

    /**
     * @var PDO
     */
    protected $_remoteDb;

    /**
     * @var t3lib_DB
     */
    protected $_db;

    /**
     * @var array
     */
    protected $_uidMap = array();

    /**
     * The time of the last pull or when the last sys_log entry was in sync
     *
     * @var integer
     */
    protected $_lastPull;

    /**
     * Pulls all database inserts
     *
     * @return void
     */
    public function pullAction()
    {
        $this->_echo(
            'This script is experimental and not ready for any production usage'
        );

        $dsn = $this->driver . ':host=' . $this->host;
        if ($this->port) {
            $dsn .= ';port=' . $this->port;
        }
        $dsn .= ';dbname=' . $this->database;

        $options = array(
            PDO::ATTR_DEFAULT_FETCH_MODE
        );

        if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['setDBinit']) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND]
                = $GLOBALS['TYPO3_CONF_VARS']['SYS']['setDBinit'];
        }

        $this->_remoteDb = new PDO($dsn, $this->user, $this->pass, $options);
        $this->_db = $GLOBALS['TYPO3_DB'];

        $this->_lastPull = $this->_findLastPull();

        $this->_pullInserts();
    }

    /**
     * Find the last pull and return the execution time
     *
     * @return integer|void Timestamp of the last pull or nothing on error
     */
    protected function _findLastPull()
    {
        $logEntry = $this->_db->exec_SELECTgetSingleRow(
            'tstamp',
            'sys_log',
            'type=4 AND details=\'' . self::LOG_CHECKPOINT_TEXT . '\'',
            '',
            'tstamp DESC'
        );

        if ($logEntry) {
            $this->_echo('Last history pull was ' . date('c', $logEntry['tstamp']));
            return (int) $logEntry['tstamp'];
        }

        $this->_echo(
            'No previous history pulls found - searching for last common sys_log '
            . 'entry'
        );

        $query = 'SELECT * FROM sys_log WHERE type = 1 ORDER BY tstamp ';
        $query .= $this->searchFromAbove ? 'DESC' : 'ASC';
        $stmt = $this->_remoteDb->query($query);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        foreach ($stmt as $row) {
            $where = array();
            foreach ($row as $column => $value) {
                $where[] = '`' . $column . '` = ' . $this->_remoteDb->quote($value);
            }

            $found = $this->_db->exec_SELECTgetSingleRow(
                'uid', 'sys_log', implode(' AND ', $where)
            );

            if ($found) {
                $this->_echo(
                    'Last common sys_log entry (' . $found['uid'] . ') was '
                    . date('c', $row['tstamp'])
                );

                return (int) $row['tstamp'];
            }
        }

        $this->_die(
            'Could not determine start date - mysql(dump) might be your tool of '
            . 'choice'
        );
    }

    /**
     * Pull all inserts
     *
     * @return void
     */
    protected function _pullInserts()
    {
        // FIXME implement this!
    }
}
?>
