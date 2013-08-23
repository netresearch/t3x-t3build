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
 * Provider to clean locallang XML source files and compile
 * them for TYPO3 in order to enable locales and avoid duplicates
 * for them in the source file. Source can e.g. look like this:
 * <code title="Source">
 *     <?xml version="1.0" encoding="utf-8" standalone="yes" ?>
 *     <T3locallang>
 *         <meta type="array">
 *             <description>Language labels for Contact Form</description>
 *         </meta>
 *         <data type="array">
 *             <languageKey index="de_DE" type="array">
 *                 <label index="hello">Hallo</label>
 *                 <label index="country">Deutschland</label>
 *             </languageKey>
 *             <languageKey index="de_AT" type="array">
 *                 <label index="country">Österreich</label>
 *             </languageKey>
 *         </data>
 *     </T3locallang>
 * </code>
 *
 * <code title="Compilation result">
 *     <?xml version="1.0" encoding="utf-8" standalone="yes" ?>
 *     <T3locallang>
 *         <meta type="array">
 *             <description>Language labels for Contact Form</description>
 *         </meta>
 *         <data type="array">
 *             <languageKey index="de" type="array">
 *                 <label index="hello">Hallo</label>
 *                 <label index="country">Deutschland</label>
 *             </languageKey>
 *             <languageKey index="at" type="array">
 *                 <label index="hello">Hallo</label>
 *                 <label index="country">Österreich</label>
 *             </languageKey>
 *         </data>
 *     </T3locallang>
 * </code>
 *
 * @package t3build
 * @author  Christian Opitz <christian.opitz@netresearch.de>
 * @license http://opensource.org/licenses/gpl-license GPLv2 or later
 *
 */
class tx_t3build_provider_lang extends tx_t3build_provider_abstract
{
    /**
     * The path to the file to clean and compile (relative to typo3 root, maybe
     * prefixed with EXT:) - will be filled with the compiled XML by default
     * (@see --cleaned-name and --compiled-name)
     *
     * @arg
     * @required
     * @var string
     */
    protected $file;

    /**
     * The name of the file in which to write the cleaned XML for
     * developers/translators - if it exists, it will be used as source.
     * Can hold pathinfo (php.net/manual/en/function.pathinfo.php) parts as
     * variables.
     *
     * @arg
     * @var string
     */
    protected $cleanedFile = '${dirname}/${filename}.source.${extension}';

    /**
     * The name of the file in which to write the cleaned and compiled XML for
     * developers/translators - if it exists, it will be used as source.
     * Can hold pathinfo (php.net/manual/en/function.pathinfo.php) parts as
     * variables.
     *
     * @arg
     * @var string
     */
    protected $compiledFile = '${dirname}/${basename}';

    /**
     * If enabled, empty labels will be tracked as missing
     *
     * @arg
     * @var boolean
     */
    protected $emptyIsMissing = true;

    /**
     * The language which keys will be used to clean up the other languages
     *
     * @arg
     * @var string
     */
    protected $master = 'first';

    /**
     * The meta data of the language file
     *
     * @var array
     */
    protected $arMeta = array();

    /**
     * The languages found in the processed language file
     *
     * @var array
     */
    protected $languages = array();

    /**
     * The labels of the processed language file assigned to the respective language
     *
     * @var array
     */
    protected $labels = array();

    /**
     * A list of labels existing in the main language but missing in the other
     *
     * @var array
     */
    protected $missing = array();

    /**
     * Contains the cleaned and compiled labels
     *
     * @var array
     */
    protected $processed = array();

    /**
     * The language action
     *
     * @return void
     */
    public function langAction()
    {
        $this->file = t3lib_div::getFileAbsFileName($this->file);

        if (!file_exists($this->file)) {
            $this->_die('File %s does not exist', $this->file);
        }

        $pathinfo = pathinfo($this->file);

        foreach (array('cleaned', 'compiled') as $type) {
            $this->{$type . 'File'} = $this->getPath(
                $this->{$type . 'File'}, $pathinfo, 'camelCase', true
            );
        }

        $this->read();
        $this->prepare();
        $this->process();
        $this->write();

        if (count($this->missing)) {
            $this->_echo('Missing translations:');

            foreach ($this->missing as $code => $indexes) {
                foreach ($indexes as $index) {
                    $this->_echo($code . ': ' . $index);
                };
            }
        }
    }

    /**
     * Read the source xml file
     *
     * @return void
     */
    protected function read()
    {
        $xml = simplexml_load_file(
            file_exists($this->cleanedFile) ? $this->cleanedFile : $this->file
        );

        // Fetch the meta data
        foreach ($xml->meta->children() as $key => $value) {
            $this->arMeta[$key] = $value;
        }

        // Fetch the language keys and labels
        foreach ($xml->data[0]->languageKey as $languageKey) {
            $parts = explode('_', strtolower((string) $languageKey['index']), 2);

            if (count($parts) === 2 && $parts[0] != $parts[1]) {
                $code = $parts[1];
                $this->languages[$code] = $parts[0];
            } else {
                $code = $parts[0];
            }

            if ($code == 'default') {
                $code = 'en';
            }

            if ($this->master == 'first') {
                $this->master = $code;
            }

            $this->labels[$code] = array();

            foreach ($languageKey->label as $label) {
                $this->labels[$code][(string) $label['index']]
                    = trim((string) $label);
            }
        }
    }

    /**
     * Prepare the language labels
     *
     * @return void
     */
    protected function prepare()
    {
        $orderedLabels = array();
        foreach ($this->labels as $code => $labels) {
            if (array_key_exists($code, $this->languages)
                && !array_key_exists($this->languages[$code], $this->labels)
            ) {
                $orderedLabels[$this->languages[$code]] = $labels;
            }

            $orderedLabels[$code] = $labels;
        }

        $this->labels = $orderedLabels;
    }

    /**
     * Clean the language labels
     *
     * @return void
     */
    protected function process()
    {
        $master = array_pop(explode('_', strtolower($this->master)));

        if (!array_key_exists($master, $this->labels)) {
            $this->_die('Master language %s not found as langKey', $master);
        }

        $cleaned = array($master => $this->labels[$master]);
        $compiled = $cleaned;

        $missing = array();
        foreach ($this->labels as $code => $labels) {
            if ($code == $master) {
                continue;
            }

            $cleaned[$code] = array();
            $countryCode = array_key_exists($code, $this->languages)
                ? $this->languages[$code] : $code;

            foreach (array_keys($this->labels[$master]) as $index) {
                $label = array_key_exists($index, $labels) ? $labels[$index] : null;
                $fallback = null;

                if ($countryCode != $code) {
                    if (array_key_exists($countryCode, $this->labels)
                        && array_key_exists($index, $this->labels[$countryCode])
                    ) {
                        $fallback = $this->labels[$countryCode][$index];

                        if ($label == $fallback) {
                            $label = null;
                        }
                    }

                    if ($label !== null) {
                        $cleaned[$code][$index] = $label;
                    }
                } else {
                    $cleaned[$code][$index] = $label;

                    if ($label === null || ($this->emptyIsMissing && !$label)) {
                        if (!array_key_exists($code, $this->missing)) {
                            $this->missing[$code] = array();
                        }

                        $this->missing[$code][$index] = $index;
                    }
                }

                if ($label !== null || $fallback !== null) {
                    $compiled[$code][$index] = $label !== null ? $label : $fallback;
                }
            }
        }

        $this->processed = compact('cleaned', 'compiled');
    }

    /**
     * Create the language xml and write it to the file
     *
     * @return void
     */
    protected function write()
    {
        foreach ($this->processed as $type => $language) {
            $content = array();
            $content[] = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>';
            $content[] = '<T3locallang>';
            $content[] = '	<meta type="array">';

            foreach ($this->arMeta as $key => $value) {
                $content[] = '        <' . $key . '>' . $value . '</' . $key . '>';
            }

            $content[] = '	</meta>';
            $content[] = '	<data type="array">';

            foreach ($language as $code => $labels) {
                $content[] = '        <languageKey index="'
                    . ($type == 'cleaned'
                       && array_key_exists($code, $this->languages)
                       ? $this->languages[$code].'_'.strtoupper($code) : $code)
                    . '" type="array">';

                foreach ($labels as $index => $label) {
                    $cdata = strpos($label, '<') !== false;
                    $content[] = '            <label index="' . $index . '">'
                        . ($cdata ? '<![CDATA[' . $label . ']]>'
                            : htmlspecialchars($label))
                        . '</label>';
                }

                $content[] = '        </languageKey>';
            }

            $content[] = '    </data>';
            $content[] = '</T3locallang>';

            $this->_echo('Writing %s XML to %s', $type, $this->{$type . 'File'});
            file_put_contents($this->{$type.'File'}, implode("\n", $content));
        }
    }
}
?>
