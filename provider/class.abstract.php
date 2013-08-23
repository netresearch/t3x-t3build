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
 * Base class for providers - which extracts the CLI help
 * from the docBlocks of the class and the class vars which
 * have an @arg tag.
 *
 * If you label an argument with @required it will be
 * required and checked upfront - if it's missing, the
 * execution will stop with an error.
 *
 * If you need wildcard arguments (eg. to pass them to
 * another provider) you can label them with @mask:
 * @mask --clean-*
 *
 * The type (@var) of the arguments will be considered and
 * CLI args will be casted accordingly before execution.
 *
 * When there are setter methods for the arguments
 * (setArgument) they will be called instead of directly
 * setting the class vars.
 *
 * @package t3build
 * @author  Christian Opitz <christian.opitz@netresearch.de>
 * @license http://opensource.org/licenses/gpl-license GPLv2 or later
 */
abstract class tx_t3build_provider_abstract
{
    /**
     * Missing arguments
     *
     * @var array
     */
    private $_missing = array();

    /**
     * Argument information array
     *
     * @var array
     */
    private $_infos = array();

    /**
     * Required arguments
     *
     * @var array
     */
    private $_requireds = array();

    /**
     * Reflection of $this class
     *
     * @var ReflectionClass
     */
    private $_class;

    /**
     * Override this if you want the default action to be another than that with the
     * class name + 'Action' as method name.
     *
     * @var string
     */
    protected $defaultActionName;

    /**
     * Print debug information
     *
     * @arg
     * @var boolean
     */
    protected $debug = false;

    /**
     * Print help information
     *
     * @arg
     * @var boolean
     */
    protected $help = false;

    /**
     * Non interactive mode: Exit on required input or use default when available
     *
     * @arg
     * @var boolean
     */
    protected $nonInteractive = false;

    /**
     * Yes to all: Answer all yes/no questions with yes
     *
     * @arg
     * @var boolean
     */
    protected $yesToAll = false;

    /**
     * The raw cli args as passed from TYPO3
     *
     * @var array
     */
    protected $cliArgs = array();

    /**
     * The stdIn resource
     *
     * @var resource
     */
    private $stdIn;

    /**
    * Initialization: Retrieve the information about the arguments and set the
    * corresponding class vars accordingly or fail the execution when @required
    * arguments are missing.
    *
    * @param array $args
    *
    * @return void
    */
    public function init($args)
    {
        if (!TYPO3_cliMode) {
            $this->_debug('Not in CLI mode - entering non-interactive mode');
            $this->nonInteractive = true;
        }

        $this->cliArgs = $args;
        $this->_class = new ReflectionClass($this);
        $masks = array();
        $modifiers = array();

        foreach ($this->_class->getProperties() as $i => $property) {
            if (preg_match_all(
                    '/^\s+\*\s+@([^\s]+)(.*)$/m',
                    $property->getDocComment(), $matches
                )
            ) {
                if (!in_array('arg', $matches[1])) {
                    continue;
                }

                $filteredName = ltrim($property->getName(), '_');
                $name = ucfirst($filteredName);
                preg_match_all('/[A-Z][a-z]*/', $name, $words);
                $shorthand = '';
                $switch = strtolower(implode('-', $words[0]));
                $shorthand = !array_key_exists('-'.$filteredName[0], $modifiers)
                    ? $filteredName[0] : null;
                $info = array(
                    'setter' => method_exists($this, 'set' . $name)
                        ? 'set' . $name : null,
                    'property' => $property->getName(),
                    'switch' => $switch,
                    'shorthand' => $shorthand,
                    'comment' => $property->getDocComment(),
                    'type' => null,
                    'mask' => null
                );

                $maskKey = array_search('mask', $matches[1]);
                if ($maskKey !== false && $matches[2][$maskKey]) {
                    $info['type'] = 'mask';
                    $info['mask'] = ltrim(trim($matches[2][$maskKey]), '-');
                    $info['shorthand'] = $shorthand = null;
                    $info['switch'] = $switch = null;
                    $masks[$i] = trim($info['mask'], '*');
                } else {
                    $varKey = array_search('var', $matches[1]);

                    if ($varKey !== false) {
                        $info['type'] = trim($matches[2][$varKey]);
                    }
                }
                $this->_infos[$i] = $info;
                $this->_requireds[$i] = in_array('required', $matches[1]);
                if ($shorthand) {
                    $modifiers['-' . $shorthand] = $i;
                }
                if ($switch) {
                    $modifiers['--' . $switch] = $i;
                }
            }
        }
        $values = array();
        foreach ($args as $argument => $value) {
            if (!preg_match('/^(-{1,2})(.+)/', $argument, $parts)) {
                continue;
            }

            $realArgs = array($parts[2]);
            $realValues = array($value);
            $argsCount = 1;
            for ($n = 0; $n < $argsCount; $n++) {
                $modifier = $parts[1] . $realArgs[$n];

                if (!array_key_exists($modifier, $modifiers)) {
                    if ($argsCount == 1) {
                        foreach ($masks as $i => $mask) {
                            if (substr($parts[2], 0, $l = strlen($mask)) == $mask) {
                                if (!isset($values[$i])) {
                                    $values[$i] = (array) $this
                                        ->{$this->_infos[$i]['property']};
                                }

                                $values[$i][$parts[1].substr($parts[2], $l)]
                                    = $value;
                                break 2;
                            }
                        }

                        if ($parts[1] == '-') {
                            // Args were passed like -dnp 7
                            // => Last arg is the real value, the others empty
                            $realArgs = str_split('0' . $parts[2]);
                            $argsCount = count($realArgs);
                            $realValues = array_fill(0, $argsCount - 2, array());
                            $realValues[$argsCount - 1] = $value;
                            continue;
                        }
                    }

                    $this->_die('Unknown modifier "%s"', $modifier);
                }

                $value = $realValues[$n];
                $i = $modifiers[$modifier];

                switch ($this->_infos[$i]['type']) {
                    case 'boolean':
                    case 'bool':
                        $value = !count($value)
                            || !in_array($value[0], array('false', '0'), true)
                            ? true : false;
                        break;
                    case 'string':
                        $value = implode(',', $value);
                        break;
                    case 'int':
                    case 'integer':
                        $value = (int) $value[0];
                        break;
                    case 'float':
                        $value = (float) $value[0];
                        break;
                    case 'array':
                        break;
                    default:
                        $value = $value[0];
                }

                if ($this->_infos[$i]['property'] == 'debug') {
                    $this->debug = $value;
                }

                $values[$i] = $value;
            }
        }

        foreach ($values as $i => $value) {
            if ($this->_infos[$i]['setter']) {
                $this->_debug(
                    'Calling setter ' . $this->_infos[$i]['setter'].' with ', $value
                );
                $this->{$this->_infos[$i]['setter']}($value);
            } else {
                $this->_debug(
                    'Setting property ' . $this->_infos[$i]['property'] . ' to ',
                    $value
                );
                $this->{$this->_infos[$i]['property']} = $value;
            }
        }

        foreach ($this->_requireds as $i => $required) {
            if ($required && !array_key_exists($i, $values)) {
                $this->_missing[] = '"' . $this->_infos[$i]['switch'] . '"';
            }
        }
    }

    /**
     * Render the help from the argument information
     *
     * @return string The command line help
     */
    protected function renderHelp()
    {
        preg_match_all(
            '/^\s+\* ([^@\/].*)$/m', $this->_class->getDocComment(), $lines
        );
        $help = implode("\n", $lines[1]) . "\n\n"
            . 'php ' . $_SERVER['PHP_SELF'];

        foreach ($this->_requireds as $i => $required) {
            if ($required) {
                $help .= ' -' . $this->_infos[$i]['shorthand'] . ' "'
                    . $this->_infos[$i]['switch'] . '"';
            }
        }

        $longest = 0;
        $order = array();

        foreach ($this->_infos as $i => $info) {
            // Help stuff
            preg_match_all('/^\s+\* ([^@\/].*)$/m', $info['comment'], $lines);
            $this->_infos[$i]['desc']    = $lines[1];
            $this->_infos[$i]['default'] = $this->{$info['property']};

            if ($this->_infos[$i]['mask']) {
                $this->_infos[$i]['switchDesc'] = '-' . $this->_infos[$i]['mask']
                    . ', --' . $this->_infos[$i]['mask'];
            } else {
                $this->_infos[$i]['switchDesc'] = '--' . $info['switch'];

                if ($info['shorthand']) {
                    $this->_infos[$i]['switchDesc'] = '-' . $info['shorthand']
                        . ' [' . $this->_infos[$i]['switchDesc'] . ']';
                }
            }

            $length = strlen($this->_infos[$i]['switchDesc']);

            if ($length > $longest) {
                $longest = $length;
            }

            $order[$i] = $info['switch'];
        }

        asort($order);
        $help .= PHP_EOL.PHP_EOL;
        $pre = str_repeat(' ', $longest + 1);

        foreach (array_keys($order) as $i) {
            $info    = $this->_infos[$i];
            $length  = strlen($info['switchDesc']);
            $default = $info['default'];

            if ($default !== '' && $default !== null) {
                if ($default === true) {
                    $default = 'true';
                } elseif ($default === false) {
                    $default = 'false';
                } elseif ($info['type'] == 'array') {
                    $default = implode(', ', (array) $default);
                }

                $info['desc'][] .= '(defaults to "' . $default . '")';
            }

            $help .= $info['switchDesc'] . str_repeat(' ', $longest - $length + 1)
                . ':' . ' '
                . implode(PHP_EOL . str_repeat(' ', $longest + 3), $info['desc'])
                . PHP_EOL;
        }

        return $help;
    }

    /**
     * Retrieve option infos with current values
     *
     * @return array Argument information array
     */
    public function getOptionInfos()
    {
        $infos = array();
        foreach ($this->_infos as $i => $info) {
            if ($info['setter']
                && method_exists($this, $method = 'g' . substr($info['setter'], 1))
            ) {
                $info['value'] = $this->{$method}();
            } else {
                $info['value'] = $this->{$info['property']};
            }

            $infos[$i] = $info;
        }

        return $infos;
    }

    /**
     * Output help
     *
     * @return void
     */
    public function helpAction()
    {
        $this->_echo($this->renderHelp());
    }

    /**
     * Run the provider
     *
     * @param string|null $action The action to run, if nothing is set the help
     *                            action is used
     *
     * @return mixed|void The returned value from the called function or nothing
     */
    public function run($action = null)
    {
        if ($this->help) {
            $action = 'help';
        }

        if (!$action) {
            if ($this->defaultActionName) {
                $action = $this->defaultActionName;
            } else {
                $methods = $this->_class->getMethods();
                $actions = array();

                foreach ($methods as $method) {
                    /* @var $method ReflectionMethod */
                    if ($method->name != 'helpAction'
                        && substr($method->name, -6) == 'Action'
                    ) {
                        $actions[$name = substr($method->name, 0, -6)] = $name;
                    }
                }

                if (count($actions) == 1) {
                    $action = array_shift($actions);
                }
            }
        }

        if (!$action) {
            $this->_echo('No action provided');
            $action = 'help';
        }

        if (!is_callable(array($this, $action . 'Action'))) {
            $this->_echo('Invalid action "' . $action . '"');
            $action = 'help';
        }

        if (count($this->_missing) && $action != 'help') {
            $this->_echo(
                'Missing argument' . (count($this->_missing) > 1 ? 's' : '') . ' %s',
                $this->_missing
            );
            $action = 'help';
        }

        return call_user_func(array($this, $action . 'Action'));
    }

    /**
     * Optionally ask $question and ask user for an answer (if in non-interactive
     * mode, it will use the default value if given or fail otherwise).
     * If $validResults are passed the user input will be validated to match one of
     * them (you can allow short answers by providing non numeric keys in this array)
     *
     * @param string|array|null $questionOrValidResults The question or the content
     *                                                  for $validResults or null for
     *                                                  no question
     * @param array|null        $validResults           An array with the valid
     *                                                  results for the user input.
     *                                                  Will be overridden if
     *                                                  $questionOrValidResults is an
     *                                                  array
     * @param string|null       $default                The default answer
     *
     * @return void
     */
    protected function _input(
        $questionOrValidResults = null, $validResults = null, $default = null
    ) {
        if (is_array($questionOrValidResults)) {
            $validResults = $questionOrValidResults;
            $questionOrValidResults = null;
        }

        if ($questionOrValidResults) {
            if ($default !== null) {
                $questionOrValidResults .= ' (leave empty for ' . $default . ')';
            }

            $this->_echo($questionOrValidResults);
        }

        if ($this->nonInteractive) {
            if ($default !== null) {
                $this->_echo(
                    'Non-interactive mode - answering with "' . $default . '"'
                );

                return $default;
            }

            $this->_die('Non-interactive mode - aborting');
        } else {
            if (!$this->stdIn) {
                $this->stdIn = fopen('php://stdin', 'r');
            }

            // wait for the user entering something and hit enter
            while (FALSE == ($line = fgets($this->stdIn, 1000))) {
            }
        }

        $line = trim($line);
        if ($line === '' && $default !== null) {
            $this->_echo('"' . $default . '"');

            return $default;
        }

        if (is_array($validResults) && !in_array($line, $validResults)) {
            $validResultsTemp = $validResults;

            foreach ($validResults as $key => $value) {
                if (!is_numeric($key)) {
                    if ($line == $key) {
                        return $value;
                    }

                    $validResults[$key] .= '/' . $key;
                }
            }

            $last = array_pop($validResults);
            $valid = count($validResults)
                ? implode(', ', $validResults) . ' or ' . $last : $last;
            $this->_echo('Please type ' . $valid . ': ');

            return $this->_input($validResultsTemp, $default);
        }

        return $line;
    }

    /**
     * Optionally ask a $question and ask the user for an answer (yes/y, no/n) -
     * automatically answer with yes when --yes-to-all is set
     *
     * @param string  $question The question to ask the user
     * @param boolean $default  The default answer for the question
     *
     * @return boolean True or false depending on the user input, always true if
     *                 --yes-to-all is set
     */
    protected function _inputYesNo($question = null, $default = null)
    {
        if ($this->yesToAll) {
            if ($question) {
                $this->_echo($question);
            }

            $this->_echo('yes');

            return true;
        }

        if ($default !== null) {
            $default = $default ? 'yes' : 'no';
        }

        return $this->_input($question, array('y' => 'yes', 'n' => 'no')) == 'yes';
    }

    /**
     * Echo vsprintfed string
     *
     * @param string $msg The message to echo (can contain sprintf format)
     * @param mixed  $arg Optional arguments
     * @param ...
     *
     * @return void
     */
    protected function _echo($msg)
    {
        $args = func_get_args();
        array_shift($args);

        foreach ($args as $i => $arg) {
            if (is_array($arg)) {
                $and = is_numeric($i) ? 'and' : $i;
                $last = array_pop($arg);
                $args[$i] = count($arg)
                    ? implode(', ', $arg) . ' ' . $and . ' ' . $last : $last;
            }
        }

        echo vsprintf((string) $msg, $args) . "\n";
    }

    /**
     * Echo vsprintfed string and exit with error
     *
     * @param string $msg The message to echo (can contain sprintf format)
     * @param mixed  $arg Optional arguments
     * @param ...
     *
     * @return void
     */
    protected function _die($msg)
    {
        $args = func_get_args();
        call_user_func_array(array($this, '_echo'), $args);
        exit(1);
    }

    /**
     * Dump vars only if --debug is on
     *
     * @param string $msg The message to echo
     * @param mixed  $arg Optional arguments
     * @param ...
     *
     * @return void
     */
    protected function _debug($msg)
    {
        if (!$this->debug) {
            return;
        }

        $args = func_get_args();
        echo '[Debug] ' . trim(array_shift($args));

        if (count($args)) {
            echo ' ';
            call_user_func_array('var_dump', $args);
        } else {
            echo PHP_EOL;
        }
    }

    /**
     * Write config to extConf
     *
     * @param string $extKey The extension name for which to write the config
     * @param array  $update An array with new or updated config values
     *
     * @return void
     */
    protected function writeExtConf($extKey, array $update)
    {
        global $TYPO3_CONF_VARS;

        $absPath = t3lib_extMgm::extPath($extKey);
        $relPath = t3lib_extMgm::extRelPath($extKey);

        /* @var $tsStyleConfig t3lib_tsStyleConfig */
        $tsStyleConfig = t3lib_div::makeInstance('t3lib_tsStyleConfig');
        $theConstants = $tsStyleConfig->ext_initTSstyleConfig(
            t3lib_div::getUrl($absPath . 'ext_conf_template.txt'),
            $absPath,
            $relPath,
            ''
        );

        $arr = @unserialize($TYPO3_CONF_VARS['EXT']['extConf'][$extKey]);
        $arr = is_array($arr) ? $arr : array();

        // Call processing function for constants config and data before write and
        // form rendering:
        $tsStyleConfigForm = $TYPO3_CONF_VARS['SC_OPTIONS']
            ['typo3/mod/tools/em/index.php']['tsStyleConfigForm'];
        if (is_array($tsStyleConfigForm)) {
            $_params = array(
                'fields' => &$theConstants,
                'data'   => &$arr,
                'extKey' => $extKey
            );

            foreach ($tsStyleConfigForm as $_funcRef) {
                t3lib_div::callUserFunction($_funcRef, $_params, $this);
            }

            unset($_params);
        }

        $arr = t3lib_div::array_merge_recursive_overrule($arr, $update);

        /* @var $instObj t3lib_install */
        $instObj = t3lib_div::makeInstance('t3lib_install');
        $instObj->allowUpdateLocalConf = 1;
        $instObj->updateIdentity = 'TYPO3 Extension Manager';

        // Get lines from localconf file
        $lines = $instObj->writeToLocalconf_control();
        // This will be saved only if there are no linebreaks in it !
        $instObj->setValueInLocalconfFile(
            $lines,
            '$TYPO3_CONF_VARS[\'EXT\'][\'extConf\'][\'' . $extKey . '\']',
            serialize($arr)
        );
        $instObj->writeToLocalconf_control($lines);

        t3lib_extMgm::removeCacheFiles();
    }

    /**
     * Parses $vars into a path mask and makes it FS-safe
     *
     * @param string  $mask       The path mask
     * @param array   $vars       A key value pair to replace in the path mask
     * @param string  $renameMode One of "underscore" or "camelCase", defaults to
     *                            "camelCase"
     * @param boolean $absolute   Whether to use absolute or relative path
     *
     * @return string|void The path or nothing on error
     */
    protected function getPath(
        $mask, $vars, $renameMode = 'camelCase', $absolute = false
    ) {
        $replace = array();
        foreach ($vars as $key => $value) {
            $replace[] = '${' . $key . '}';
        }

        $path = str_replace($replace, $vars, $mask);

        if (preg_match('/\$\{([^\}]*)\}/', $path, $res)) {
            $this->_die('Unknown var "' . $res[1] . '" in path mask');
        }

        $pre = '';
        if ($absolute) {
            $parts = preg_split('#\s*[\\/]+\s*#', $path);
            $rest = array();

            while (count($parts)) {
                $file = implode('/', $parts);

                if (file_exists($file)) {
                    if (!count($rest) && is_file($file)) {
                        return $file;
                    }

                    $pre = $file . '/';
                    $path = implode('/', $rest);
                    break;
                }

                array_unshift($rest, array_pop($parts));
            }
        }

        $path = strtolower($path);
        $path = str_replace(':', '-', $path);
        $path = preg_replace('#[^A-Za-z0-9/\-_\.]+#', ' ', $path);
        $path = preg_replace('#\s*/+\s*#', '/', $path);
        $parts = explode(' ', $path);

        if ($renameMode == 'underscore') {
            $path = implode('_', $parts);
        } else {
            $path = '';
            $uc = false;

            foreach ($parts as $part) {
                $ucPart = ucfirst($part);
                $path .= ($uc || $renameMode === 'CamelCase') ? $ucPart : $part;
                $uc = $ucPart != $part;
            }
        }
        return $pre . $path;
    }
}
?>
