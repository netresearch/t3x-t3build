<?php
abstract class tx_t3build_provider_abstract {

    private $_help = '';

    private $_missing = array();

    private $_infos = array();

    private $_requireds = array();

    private $_class;

    protected $defaultActionName;

    /**
     * Print debug information
     * @arg
     * @var boolean
     */
    protected $debug = false;

    /**
     * Print help information
     * @arg
     * @var boolean
     */
    protected $help = false;

    protected $cliArgs = array();

	public function init($args)
	{
	    $this->cliArgs = $args;
        $this->_class = new ReflectionClass($this);
        $masks = array();
        $modifiers = array();

        foreach ($this->_class->getProperties() as $i => $property) {
            if (preg_match_all('/^\s+\*\s+@([^\s]+)(.*)$/m', $property->getDocComment(), $matches)) {
                if (!in_array('arg', $matches[1])) {
                    continue;
                }
                $filteredName = ltrim($property->getName(), '_');
                $name = ucfirst($filteredName);
                preg_match_all('/[A-Z][a-z]*/', $name, $words);
                $shorthand = '';
                $switch = strtolower(implode('-', $words[0]));
                $shorthand = !array_key_exists('-'.$filteredName[0], $modifiers) ? $filteredName[0] : null;
                $info = array(
                    'setter' => method_exists($this, 'set'.$name) ? 'set'.$name : null,
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
                    $modifiers['-'.$shorthand] = $i;
                }
                if ($switch) {
                    $modifiers['--'.$switch] = $i;
                }
            }
        }
	    $values = array();
        foreach ($args as $argument => $value) {
            if (!preg_match('/^(-{1,2})(.+)/', $argument, $parts)) {
                continue;
            }
            $realArgs = array($parts[2]);
            $argsCount = 1;
            for ($n = 0; $n < $argsCount; $n++) {
                $modifier = $parts[1].$realArgs[$n];
                if (!array_key_exists($modifier, $modifiers)) {
                    if ($argsCount == 1) {
                        foreach ($masks as $i => $mask) {
                            if (substr($parts[2], 0, $l = strlen($mask)) == $mask) {
                                if (!isset($values[$i])) {
                                    $values[$i] = (array) $this->{$this->_infos[$i]['property']};
                                }
                                $values[$i][$parts[1].substr($parts[2], $l)] = $value;
                                break 2;
                            }
                        }
                    }
                    if ($parts[1] == '-') {
                        $realArgs = str_split($parts[2]);
                        $argsCount = count($realArgs);
                        $n = 0;
                        continue;
                    }
                    $this->_die('Unknown modifier "%s"', $modifier);
                }
                $i = $modifiers[$modifier];
                switch ($this->_infos[$i]['type']) {
                    case 'boolean':
                    case 'bool':
                        $value = !count($value) || !in_array($value[0], array('false', '0'), true) ? true : false;
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
                $this->_debug('Calling setter '.$this->_infos[$i]['setter'].' with ', $value);
                $this->{$this->_infos[$i]['setter']}($value);
            } else {
                $this->_debug('Setting property '.$this->_infos[$i]['property'].' to ', $value);
                $this->{$this->_infos[$i]['property']} = $value;
            }
        }
        foreach ($this->_requireds as $i => $required) {
            if ($required && !array_key_exists($i, $values)) {
                $this->_missing[] = '"'.$this->_infos[$i]['switch'].'"';
            }
        }
	}

	public function helpAction()
	{
        preg_match_all('/^\s+\* ([^@\/].*)$/m', $this->_class->getDocComment(), $lines);
        $help = implode("\n", $lines[1])."\n\n";
        $help .= 'php '.$_SERVER['PHP_SELF'];
        foreach ($this->_requireds as $i => $required) {
            if ($required) {
                $help .= ' -'.$this->_infos[$i]['shorthand'].' "'.$this->_infos[$i]['switch'].'"';
            }
        }

        $longest = 0;
        foreach ($this->_infos as $i => $info) {
            // Help stuff
            preg_match_all('/^\s+\* ([^@\/].*)$/m', $info['comment'], $lines);
            $this->_infos[$i]['desc'] = $lines[1];
            $this->_infos[$i]['default'] = $this->{$info['property']};
            if ($this->_infos[$i]['mask']) {
                $this->_infos[$i]['switchDesc'] = '-'.$this->_infos[$i]['mask'].', --'.$this->_infos[$i]['mask'];
            } else {
                $this->_infos[$i]['switchDesc'] = '--'.$info['switch'];
                if ($info['shorthand']) {
                    $this->_infos[$i]['switchDesc'] = '-'.$info['shorthand'].' ['.$this->_infos[$i]['switchDesc'].']';
                }
            }
            $length = strlen($this->_infos[$i]['switchDesc']);
            if ($length > $longest) {
                $longest = $length;
            }
        }

        $help .= PHP_EOL.PHP_EOL;
        $pre = str_repeat(' ', $longest+1);
        foreach ($this->_infos as $i => $info) {
            $length = strlen($info['switchDesc']);
            $default = $info['default'];
            if ($default !== '' && $default !== null) {
                if ($default === true) {
                    $default = 'true';
                } elseif ($default === false) {
                    $default = 'false';
                }
                $info['desc'][] .= '(defaults to "'.$default.'")';
            }
            $help .= $info['switchDesc'].str_repeat(' ', $longest - $length + 1).':'.' ';
            $help .= implode(PHP_EOL.str_repeat(' ', $longest+3), $info['desc']);
            $help .= PHP_EOL;
        }
        $this->_echo($help);
	}

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
                    if ($method->name != 'helpAction' && substr($method->name, -6) == 'Action') {
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
        if (!is_callable(array($this, $action.'Action'))) {
            $this->_echo('Invalid action "'.$action.'"');
            $action = 'help';
        }
        if (count($this->_missing) && $action != 'help') {
            $this->_echo('Missing argument'.(count($this->_missing) > 1 ? 's' : '').' %s', $this->_missing);
            $action = 'help';
        }
        return call_user_func(array($this, $action.'Action'));
    }

    protected function _echo($msg)
    {
        $args = func_get_args();
        array_shift($args);
        foreach ($args as $i => $arg) {
            if (is_array($arg)) {
                $and = is_numeric($i) ? 'and' : $i;
                $last = array_pop($arg);
                $args[$i] = count($arg) ? implode(', ', $arg).' '.$and.' '.$last : $last;
            }
        }
        echo vsprintf((string) $msg, $args)."\n";
    }

    protected function _die($msg)
    {
        $args = func_get_args();
        call_user_func_array(array($this, '_echo'), $args);
        die();
    }

    protected function _debug($msg)
    {
        if (!$this->debug) {
            return;
        }
        $args = func_get_args();
        echo '[Debug] '.trim(array_shift($args));
        if (count($args)) {
            echo ' ';
            call_user_func_array('var_dump', $args);
        } else {
            echo PHP_EOL;
        }
    }
}
