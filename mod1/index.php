<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009-2011 Ingo Renner <ingo@typo3.org>
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


$LANG->includeLLFile('EXT:t3build/mod1/locallang.xml');
	// This checks permissions and exits if the users has no permission for entry.
$BE_USER->modAccess($MCONF, 1);

require_once t3lib_extMgm::extPath('t3build').'classes/class.tx_t3build_providerInfo.php';


/**
 * Module 't3build' for the 't3build' extension.
 *
 * @author		Ingo Renner <ingo@typo3.org>
 * @package		TYPO3
 * @subpackage	tx_t3build
 *
 * $Id$
 */
class tx_t3build_module extends t3lib_SCbase {

	protected $pageinfo;

	/**
	 * @var tx_t3build_providerInfo
	 */
	protected $providerInfo;

	/**
	 * Initializes the Module
	 *
	 * @return	void
	 */
	public function __construct() {
		parent::init();

		$this->providerInfo = t3lib_div::makeInstance('tx_t3build_providerInfo');

			// initialize document
		$this->doc = t3lib_div::makeInstance('template');
		$this->doc->setModuleTemplate(
			t3lib_extMgm::extPath('t3build') . 'mod1/mod_template.html'
		);
		$this->doc->backPath = $GLOBALS['BACK_PATH'];
		$this->doc->addStyleSheet(
			'tx_t3build',
			'../' . t3lib_extMgm::siteRelPath('t3build') . 'mod1/mod_styles.css'
		);
	}

	/**
	 * Adds items to the ->MOD_MENU array. Used for the function menu selector.
	 *
	 * @return	void
	 */
	public function menuConfig() {
		$this->MOD_MENU = array(
			'function' => array(
			    'status' => $GLOBALS['LANG']->getLL('status'),
			    'providers' => $GLOBALS['LANG']->getLL('providers'),
			)
		);

		parent::menuConfig();
	}

	/**
	 * Creates the module's content. In this case it rather acts as a kind of #
	 * dispatcher redirecting requests to specific t3build.
	 *
	 * @return	void
	 */
	public function main() {
		$docHeaderButtons = $this->getButtons();

			// Access check!
			// The page will show only if user has admin rights
		if ($GLOBALS['BE_USER']->user['admin']) {
				// Draw the form
			$this->doc->form = '<form action="" method="post" enctype="multipart/form-data">';
				// JavaScript
			$this->doc->JScodeArray[] = '
				script_ended = 0;
				function jumpToUrl(URL) {
					document.location = URL;
				}
			';
			$this->doc->postCode='
				<script language="javascript" type="text/javascript">
					script_ended = 1;
					if (top.fsMod) {
						top.fsMod.recentIds["web"] = 0;
					}
				</script>
			';
				// Render content:
    		$action  = (string) $this->MOD_SETTINGS['function'];
    		$content = call_user_func(array($this, $action.'Action'));
    		$title = $this->MOD_MENU['function'][$action];
    		$this->content .= $this->doc->section($title, $content, false, true);
		} else {
				// If no access or if ID == 0
			$docHeaderButtons['save'] = '';
			$this->content.=$this->doc->spacer(10);
		}

			// compile document
		$markers['FUNC_MENU'] = $GLOBALS['LANG']->getLL('choose_report')
			. t3lib_BEfunc::getFuncMenu(
				0,
				'SET[function]',
				$this->MOD_SETTINGS['function'],
				$this->MOD_MENU['function']
			);
		$markers['CONTENT'] = $this->content;

				// Build the <body> for the module
		$this->content = $this->doc->moduleBody($this->pageinfo, $docHeaderButtons, $markers);
			// Renders the module page
		$this->content = $this->doc->render(
			$GLOBALS['LANG']->getLL('title'),
			$this->content
		);
	}

	/**
	 * Prints out the module's HTML
	 *
	 * @return	void
	 */
	public function printContent() {
		echo $this->content;
	}

	public function flush($string)
	{
	    static $i = 0;
	    $i++;
	    $string .= '<a name="jump-'.$i.'"></a><script type="text/javascript">window.location.hash = "jump-'.$i.'";</script>';
	    return str_pad(nl2br($string), intval(ini_get('output_buffering')))."\n";
	}

	protected function providersAction()
	{
	    $action = t3lib_div::_GP('action') ? t3lib_div::_GP('action') : 'configureProvider';
	    $provider = t3lib_div::_GP('provider') ? t3lib_div::_GP('provider') : '';

        $content = '<input type="hidden" name="action" value="'.$action.'"/>';
        $content .= '<input type="hidden" id="providerInput" name="provider" value="'.$provider.'"/>';

        if (t3lib_div::_GP('action')) {
            $content .= call_user_func(array($this, $action.'Action'));
            return $content;
        }

        $content .= '<dl class="t3-overview-list">';
		foreach ($this->providerInfo->getProviders() as $provider) {
		    $instance = $this->providerInfo->getProviderInstance($provider);
		    $reflection = new ReflectionClass($instance);
            preg_match_all('/^\s+\* ([^@\/].*)$/m', $reflection->getDocComment(), $lines);
            $help = nl2br(trim(implode("\n", $lines[1])));
            $content .= '<dt><a href="#" onclick="$(\'providerInput\').value = \''.$provider.'\'; $$(\'form\')[0].submit();">'.$provider.'</a></dt>';
			$content .= '<dd style="white-space:pre;">'.htmlspecialchars(trim(implode("\n", $lines[1]))).'</dd>';
		}
		$content .= '</dl>';
		return $content;
	}

	protected function configureProviderAction()
	{
	    $post = $_POST;

	    $provider = $this->providerInfo->getProviderInstance($post['provider']);

	    if ($post['run']) {
	        $this->runProvider($provider, $post['args']);
	    } else {
	        $provider->init(array());
	    }

	    $form .= '
	    <div class="t3buildButtons">
    	    <input type="submit" name="run" value="Run"/>&nbsp;
    	    <div class="divider" style="margin-bottom:10px;"></div>
	    </div>
	    <div class="t3buildFormContainer">';
	    $i = 0;
	    foreach ($provider->getOptionInfos() as $info) {
	        if ($info['type'] == 'mask') {
	            // Not supported
	            continue;
	        }
	        $i++;
	        if ($i > 1 && $i % 2) {
	            $form .= '<div class="t3buildFormElementRowDivider"></div>';
	        }
	        $value = $info['value'];
	        $name = 'args[--'.$info['switch'].']';
            $title = ucfirst(trim(preg_replace('/([A-Z])/', ' $1', $info['property']), ' _'));
            preg_match_all('/^\s+\* ([^@\/].*)$/m', $info['comment'], $lines);
            $description = nl2br(trim(implode("\n", $lines[1])));

            $form .= '<dl class="t3buildFormElement">';
            $form .= '<dt>'.$title.'</dt>';
            $form .= '<dd>'.$description.'</dd>';

            switch ($info['type']) {
                case 'boolean':
                case 'bool':
                    $form .= '<dd>
                    <input type="checkbox" name="'.$name.'[1]" value="true"';
                    if ($value) {
                        $form .= ' checked="checked"';
                    }
                    $form .= '/></dd>';
                    break;
                case 'array':
                    foreach ($value as $subValue) {
                        $form .= '<dd><input type="text" name="'.$name.'[]" value="'.$subValue.'"/></dd>';
                    }
                    break;
                case 'string':
                case 'int':
                case 'integer':
                case 'float':
                default:
                    $form .= '<dd><input type="text" name="'.$name.'[]" value="'.$value.'"/></dd>';
            }
            $form .= '</dl>';
	    }
        $form .= '
        </div>
	    	<div class="divider" style="margin-bottom:5px;"></div>
    	    <input type="submit" name="run" value="Run"/>&nbsp;
    	    <div class="divider" style="margin-bottom:10px;"></div>';
	    $form .= '<script type="text/javascript">'."
    		$$('form')[0]
    		.writeAttribute('target', 'consoleOutput')
    		.observe('submit', function(e) {
    			window.location.hash = 'console_output';
    			$('consoleOutputContainer').show();
    		});
	    </script>".'
    	<div id="consoleOutputContainer" style="display:none;">
    		<iframe id="consoleOutput" name="consoleOutput" frameborder="no" src="../'.t3lib_extMgm::siteRelPath('t3build').'mod1/empty.html" width="100%" height="100%"></iframe>
        </div>
	    <a name="console_output"/>';

        return $form;
	}

	protected function runProvider($provider, $args)
	{
        // Set unchecked booleans to false:
	    foreach ($provider->getOptionInfos() as $info) {
	        if (substr($info['type'], 0, 4) == 'bool' && !isset($args['--'.$info['switch']])) {
	            $args['--'.$info['switch']] = array('false');
	        }
	    }

        // prepare console output in iframe:
        t3lib_div::cleanOutputBuffers();
        ob_implicit_flush(true);
        ob_start(array($this, 'flush'), 2);

        echo '<body style="color:#fff; background:#000; font:normal 11px Lucida Console, Courier New, serif; white-space:nowrap;">';

        // Initialize again with the post args and run it
        $provider->init($args);
        $provider->run();

        echo '</body>';
        die();
	}

	/**
	 * Shows an overview list of available t3build.
	 *
	 * @return	string	list of available t3build
	 */
	protected function statusAction() {
	    /* @var $LANG language */
	    global $LANG;
		$sections = array();

		$sections['general'] = array();
		$sections['general'][] = $this->getBeUserStatus();
		$sections['mysqlCli'] = $this->getMySqlCliStatus();

		$template = '
		<div class="typo3-message message-###CLASS###">
			<div class="header-container">
				<div class="message-header message-left">###HEADER###</div>
				<div class="message-header message-right">###STATUS###</div>
			</div>
			<div class="message-body">###CONTENT###</div>
		</div>';
		foreach ($sections as $sectionName => $section) {
		    $sectionContent = '';
		    foreach ($section as $entryName => $entry) {
		        $sectionContent .= strtr($template, array(
		            '###CLASS###' => $entry['class'],
		            '###HEADER###' => $entry['header'],
		            '###STATUS###' => $entry['status'],
		            '###CONTENT###' => $entry['content']
		        ));
		    }
			$content .= $GLOBALS['TBE_TEMPLATE']->collapseableSection($GLOBALS['LANG']->getLL($sectionName), $sectionContent, $sectionName, 't3build.status');
		}
		return $content;
	}

	protected function getMySqlCliStatus()
	{
		$requiredCmds = array('mysql', 'mysqldump');
	    $sections = array();
		foreach ($requiredCmds as $cmd) {
		    if (!t3lib_exec::checkCommand($cmd)) {
		        $error = true;
		        $status = $GLOBALS['LANG']->getLL('cmdNotAvailable');
		    } else {
		        $exec = t3lib_exec::getCommand($cmd);
		        $output = array();
    	        exec($exec.' --version', $output, $retVar);
    	        if ($retVar) {
    		        $error = true;
    		        $status = $GLOBALS['LANG']->getLL('cmdAvailableButError');
    	        } else {
    	            $error = false;
    	            $res = implode('<br/>', $output);
    	            $status = substr($res, strrpos($res, 'Ver'));
    	        }
		    }
		    $sections[] = array(
		    	'header' => $cmd,
		        'class' => $error ? 'error' : 'ok',
		        'status' => $status
		    );
		}
		return $sections;
	}

	protected function getBeUserStatus()
	{
	    /* @var $LANG language */
	    global $LANG;
		/* @var $beUser t3lib_beUserAuth */
		$beUser = clone $GLOBALS['BE_USER'];
		$requiredName = '_cli_t3build';
		$beUser->setBeUserByName($requiredName);
		if (!$beUser->user['uid']) {
		    $error = true;
		    $status = $LANG->getLL('notExists').
		    ' <a href="'.htmlspecialchars($GLOBALS['MCONF']['_'].'&CMD=createBeUser').'">'.$LANG->getLL('createBeUser').'</a>';
		    if ($this->CMD == 'createBeUser') {
		        $data = array(
		        	'be_users' => array(
		        		'NEW' => array(
		        			'username' => $requiredName,
		        			'password' => md5(uniqid('t3build', true)),
		        			'pid' => 0
		                )
		            )
		        );
		    }
		} elseif ($beUser->isAdmin()) {
		    $error = true;
		    $status = $LANG->getLL('isAdmin').
		    ' <a href="'.htmlspecialchars($GLOBALS['MCONF']['_'].'&CMD=changeBeUser').'">'.$LANG->getLL('changeBeUser').'</a>';
		    if ($this->CMD == 'changeBeUser') {
		        $data = array('be_users' => array($beUser->user['uid'] => array('admin' => 0)));
		    }
		} else {
		    $error = false;
		    $status = $LANG->getLL('exists');
		}

		if (isset($data)) {
		    $tcemain = t3lib_div::makeInstance('t3lib_TCEmain');
			$tcemain->stripslashes_values = 0;
			$tcemain->start($data, array());
			$tcemain->process_datamap();
			$this->addMessage($this->CMD.'.success');
			return $this->getBeUserStatus();
		}

		return array(
		    'header' => $LANG->getLL('beUser'),
		    'class' => $error ? 'error' : 'ok',
		    'status' => '<em>'.$requiredName.'</em> '.$status
		);
	}

	/**
	 * This method is used to add a message to the internal queue
	 *
	 * @param	string	the message itself
	 * @param	integer	message level (-1 = success (default), 0 = info, 1 = notice, 2 = warning, 3 = error)
	 * @return	void
	 */
	public function addMessage($message, $severity = t3lib_FlashMessage::OK) {
		$message = t3lib_div::makeInstance(
			't3lib_FlashMessage',
			$GLOBALS['LANG']->getLL($message),
			'',
			$severity
		);

		t3lib_FlashMessageQueue::addMessage($message);
	}

	/**
	 * Create the panel of buttons for submitting the form or otherwise
	 * perform operations.
	 *
	 * @return	array	all available buttons as an assoc. array
	 */
	protected function getButtons() {
		$buttons = array(
			'csh' => '',
			'shortcut' => '',
			'save' => ''
		);
			// CSH
		$buttons['csh'] = t3lib_BEfunc::cshItem('_MOD_web_func', '', $GLOBALS['BACK_PATH']);

			// Shortcut
		if ($GLOBALS['BE_USER']->mayMakeShortcut()) {
			$buttons['shortcut'] = $this->doc->makeShortcutIcon('', 'function', $this->MCONF['name']);
		}

		return $buttons;
	}


}



if (defined('TYPO3_MODE') && isset($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/t3build/mod1/index.php'])) {
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/t3build/mod1/index.php']);
}




// Make instance:
$SOBE = t3lib_div::makeInstance('tx_t3build_module');

// Include files?
foreach($SOBE->include_once as $INC_FILE) {
	include_once($INC_FILE);
}

$SOBE->main();
$SOBE->printContent();

?>
