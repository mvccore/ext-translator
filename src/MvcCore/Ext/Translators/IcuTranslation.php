<?php

/**
 * MvcCore
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flidr (https://github.com/mvccore)
 * @license		https://mvccore.github.io/docs/mvccore/5.0.0/LICENSE.md
 */

namespace MvcCore\Ext\Translators;

/**
 * Responsibility - wrapper for PHP `\MessageFormatter`.
 * @see https://www.php.net/manual/en/class.messageformatter.php
 */
class IcuTranslation implements IIcuTranslation {

	/**
	 * Plural keyword options:
	 * - 0 - required
	 * - 1 - PHP keyword
	 * @var array<string, [bool, string|NULL]>
	 */
	protected static $pluralKeywordsOptions = [
		'zero'	=> [FALSE,  '=0'],
		'one'	=> [FALSE, NULL],
		'two'	=> [FALSE, NULL],
		'few'	=> [FALSE, NULL],
		'many'	=> [FALSE, NULL],
		'other'	=> [TRUE, NULL],
	];

	/**
	 * International language code in lower case or (international 
	 * language code in lower case plus dash and plus international 
	 * locale code in upper case).
	 * @var string
	 */
	protected $localization = NULL;

	/**
	 * I18n ICU translation pattern.
	 * @see https://formatjs.io/docs/core-concepts/icu-syntax/
	 * @var string|NULL
	 */
	protected $pattern = NULL;

	/**
	 * Boolean if pattern is parsed and modified for PHP `\MessageFormatter`.
	 * @var mixed
	 */
	protected $parsed = FALSE;

	/**
	 * Variables matched in pattern - necessary dictionary for parsing.
	 * Key is variable name, value is boolean, if `TRUE`, variable has options.
	 * @var array<string, bool>
	 */
	protected $variables = [];

	/**
	 * @see https://www.php.net/manual/en/class.messageformatter.php
	 * @var \MessageFormatter|NULL
	 */
	protected $msgFormater = NULL;

	/**
	 * @inheritDocs
	 * @param  string $translationValue 
	 * @return bool
	 */
	public static function Detect ($translationValue) {
		return (bool) preg_match(
			"#\{([^\}]+)\,\s?(plural|select|selectordinal|date|time|number)\s?\,([^\}]+)\}#", 
			$translationValue
		);
	}

	/**
	 * Create PHP `\MessageFormatter` wrapper.
	 * @param  string $localization 
	 * @param  string $pattern 
	 * @return void
	 */
	public function __construct ($localization, $pattern) {
		$this->localization = $localization;
		$this->pattern = $pattern;
	}
	
	/**
	 * @inheritDocs
	 * @param  string $localization 
	 * @return \MvcCore\Ext\Translators\IcuTranslation
	 */
	public function SetLocalization ($localization) {
		$this->localization = $localization;
		return $this;
	}
	
	/**
	 * @inheritDocs
	 * @return string
	 */
	public function GetLocalization () {
		return $this->localization;
	}
	
	/**
	 * @inheritDocs
	 * @param  string $pattern 
	 * @return \MvcCore\Ext\Translators\IcuTranslation
	 */
	public function SetPattern ($pattern) {
		$this->pattern = $pattern;
		return $this;
	}
	
	/**
	 * @inheritDocs
	 * @return string
	 */
	public function GetPattern () {
		return $this->pattern;
	}
	
	/**
	 * @inheritDocs
	 * @param  bool $parsed 
	 * @return \MvcCore\Ext\Translators\IcuTranslation
	 */
	public function SetParsed ($parsed) {
		$this->parsed = $parsed;
		return $this;
	}
	
	/**
	 * @inheritDocs
	 * @return bool
	 */
	public function GetParsed () {
		return $this->parsed;
	}

	/**
	 * @inheritDocs
	 * @throws \Exception
	 * @return bool
	 */
	public function Parse () {
		$this->convertPatternForPhpMessageFormatter();
		$this->msgFormater = new \MessageFormatter(
			$this->localization,
			$this->pattern
		);
		$this->parsed = $this->msgFormater instanceof \MessageFormatter;
		return TRUE;
	}
	
	/**
	 * @inheritDocs
	 * @param  array $replacements 
	 * @return bool|string
	 */
	public function Translate (array $replacements = []) {
		return $this->msgFormater->format($replacements);
	}

	/**
	 * Modify message pattern to work properly with PHP `\MessageFormatter`.
	 * Process i18n ICU translation pattern, find all plural variables and fix/check:
	 * - if there is keyword `one` - replace it with `=1`.
	 * - if there is no option `other` - thrown an exception.
	 * @throws \Exception
	 * @return void
	 */
	protected function convertPatternForPhpMessageFormatter () {
		$this->completeVariables();
		$levelSections = static::getMatchingBracketsPositions($this->pattern, '{', '}');
		$newPatternItems = [];
		$this->recursiveProcessSections($newPatternItems, 0, mb_strlen($this->pattern), $levelSections, 0);
		$this->pattern = implode('', $newPatternItems);
		unset($this->variables);
	}
	
	/**
	 * Find all variables in translation pattern.
	 * @return void
	 */
	protected function completeVariables () {
		$indexes = [];
		$varsCount = 0;
		$extVarBools = [',' => TRUE, '}' => FALSE];
		preg_replace_callback(
			"#\{(\w+)\s*(?=(,|\}))#", 
			function ($match) use (& $vars, & $indexes, & $varsCount, & $extVarBools) {
				$varName = $match[1];
				if (isset($this->variables[$varName])) 
					return $match[0];
				$varIsExt = $extVarBools[$match[2]];
				$varIndex = 0;
				if (isset($indexes[$varName])) {
					$varIndex = $indexes[$varName];
				} else {
					$varIndex = $varsCount;
					$varsCount++;
					$indexes[$varName] = $varIndex;
				}
				$this->variables[$varName] = $varIsExt;
				return $match[0];
			},
			$this->pattern
		);
	}

	/**
	 * Recursively process section in current level.
	 * @param  \string[]                   $newPatternItems   Modified section string items.
	 * @param  int                         $patternStart      Index in translation pattern where section begins.
	 * @param  int                         $patternEnd        Index in translation pattern where section ends.
	 * @param  [int,int,array,string]|NULL $levelSectionItems Parsed section info - begin, end, subitems, variable name.
	 * @param  int                         $level             Level index - from zero.
	 * @throws \Exception
	 * @return void
	 */
	protected function recursiveProcessSections (& $newPatternItems, $patternStart, $patternEnd, $levelSectionItems, $level) {
		// process sections
		$patternCurrentIndex = $patternStart;
		foreach ($levelSectionItems as $levelSectionItem) {
			/**
			 * @var int $sectionBegin section begin index in translation pattern.
			 * @var int $sectionEnd section end index in translation pattern.
			 * @var array|null $subItems section options - subitems info.
			 * @var string $varName section variable name.
			 */
			list($sectionBegin, $sectionEnd, $subItems, $varName) = $levelSectionItem;
			
			// add any text before first section
			if ($sectionBegin > $patternCurrentIndex) 
				$newPatternItems[] = mb_substr(
					$this->pattern, $patternCurrentIndex, $sectionBegin - $patternCurrentIndex
				);

			$patternCurrentIndex = $sectionEnd + 1;
			
			if (!$this->variables[$varName]) {
				// section is `{varname}`:
				if ($subItems !== NULL) 
					static::thrownAnException(
						"Translation variable `{$varName}` is defined in mixed mode."
					);
				$newPatternItems[] = '{' . $varName . '}';
			} else if ($subItems === NULL) {
				// section is `{varname, number|date|time, ...}`:
				$newPatternItems[] = '{' . mb_substr(
					$this->pattern, $sectionBegin + 1, $sectionEnd - $sectionBegin - 1
				) . '}';

			} else {
				// section is `{varname, number|date|time|plural|select|selectordinal, ...}`:

				// complete section head:
				$firstSubItemBegin = $subItems[0][0];
				$sectionHead = mb_substr($this->pattern, $sectionBegin + 1, $firstSubItemBegin - $sectionBegin - 1);
				$sectionHeadTrimmed = trim($sectionHead);

				// complete section type:
				$sectionType = $this->recursiveProcessSectionsGetSectionType(
					$sectionHeadTrimmed, $varName
				);
				
				// precise section head substring:
				list ($sectionHead, $sectionOptionsBegin) = $this->recursiveProcessSectionsPreciseHeadAndOptions(
					$sectionHeadTrimmed, $sectionHead, $sectionBegin
				);
				
				// process section options recursively:
				$sectionOptions = $this->recursiveProcessSectionOptions(
					$subItems, $sectionOptionsBegin, $sectionType === 'plural', $varName, $level
				);
				
				$newPatternItems[] = '{' . $sectionHead . ' ' . $sectionOptions . '}';
			}

		}
		
		// add any text after last section
		if ($patternCurrentIndex < $patternEnd)
			$newPatternItems[] = mb_substr(
				$this->pattern, $patternCurrentIndex, $patternEnd - $patternCurrentIndex + 2
			);
	}

	/**
	 * Get i18n icu variable type - plural | select | selectordinal | date | time | number.
	 * @param  string $sectionHeadTrimmed 
	 * @param  string $varName 
	 * @return string
	 */
	protected function recursiveProcessSectionsGetSectionType ($sectionHeadTrimmed, $varName) {
		if (preg_match("#^\s*{$varName}\s*,\s*(\w+)#", $sectionHeadTrimmed, $sectionTypeMatches)) {
			return $sectionTypeMatches[1];
		} else {
			$sectionHeadItems = array_map('trim', explode(',', $sectionHeadTrimmed));
			return $sectionHeadItems[1];
		}
	}
	
	/**
	 * Specify more precisely section head and section options begin index.
	 * @param  string $sectionHeadTrimmed 
	 * @param  string $sectionHead 
	 * @param  int    $sectionBegin 
	 * @return [string, int]
	 */
	protected function recursiveProcessSectionsPreciseHeadAndOptions ($sectionHeadTrimmed, $sectionHead, $sectionBegin) {
		if (preg_match("#(\=\d+|\=\w+|\w+)$#", $sectionHeadTrimmed, $matches)) {
			$firstOptionHead = $matches[1];
			$firstOptionHeadBegin = mb_strrpos($sectionHeadTrimmed, $firstOptionHead);
			if ($firstOptionHeadBegin !== FALSE) 
				$sectionHead = mb_substr($sectionHead, 0, $firstOptionHeadBegin);
		}
		return [
			trim($sectionHead),
			$sectionBegin + 1 + mb_strlen($sectionHead)
		];
	}

	/**
	 * Process i18n ICU translation pattern plural variable section and fix/check:
	 * - if there is keyword `one` - replace it with `=1`.
	 * - if there is no option `other` - thrown an exception.
	 * @param  array      $subItems            Section options - [int, int, array|null, string].
	 * @param  int        $sectionOptionsBegin Section options pattern begin index.
	 * @param  bool       $isPlural            `TRUE` if section is plural.
	 * @param  string     $varName             Section variable name.
	 * @param  int        $level               Section level - from zero.
	 * @throws \Exception
	 * @return string                                                  Modified section options.
	 */
	protected function recursiveProcessSectionOptions ($subItems, $sectionOptionsBegin, $isPlural, $varName, $level) {
		// update section options
		$sectionOptions = [];
		$currentSubItemBegin = $sectionOptionsBegin;
		$otherOptionPresented = FALSE;
		foreach ($subItems as $subItem) {
			/**
			 * @var int $subItemIndexBegin subitem section begin index in translation pattern.
			 * @var int $subItemIndexEnd subitem section end index in translation pattern.
			 * @var array|null $subItemSubItems subitem section options - sub-subitems info.
			 */
			list($subItemIndexBegin, $subItemIndexEnd, $subItemSubItems) = $subItem;
			// complete section subitem content:
			if ($subItemSubItems === NULL) {
				$subItemContent = mb_substr($this->pattern, $subItemIndexBegin, $subItemIndexEnd - $subItemIndexBegin + 1);
			} else {
				$newPatternSubItems = [];
				$this->recursiveProcessSections(
					$newPatternSubItems, 
					$subItemIndexBegin, 
					$subItemIndexEnd,
					$subItemSubItems, 
					$level + 1
				);
				$subItemContent = implode('', $newPatternSubItems);
			}

			// complete subitem head:
			$subItemHeadLen = $subItem[0] - $currentSubItemBegin;
			$subItemOptionHead = mb_substr($this->pattern, $currentSubItemBegin, $subItemHeadLen);
			
			// complete options:
			if (!$isPlural) {
				$sectionOptions[] = trim($subItemOptionHead) . ' ' . $subItemContent;
			} else {
				$subItemOptionKey = trim(str_replace('=', '', trim($subItemOptionHead)));

				if (isset(static::$pluralKeywordsOptions[$subItemOptionKey])) {
					list($required, $phpAlias) = static::$pluralKeywordsOptions[$subItemOptionKey];
					if ($required) $otherOptionPresented = TRUE;
					$pluralKeyword = $phpAlias ?: $subItemOptionKey;
					$sectionOptions[] = "{$pluralKeyword} {$subItemContent}"; // fix zero to =0
				} else {
					$sectionOptions[] = "={$subItemOptionKey} {$subItemContent}";
				}
			}
					
			$subItemContentLen = $subItemIndexEnd - $currentSubItemBegin + 1;
			$currentSubItemBegin += $subItemContentLen;
		}
		if ($isPlural && !$otherOptionPresented)
			static::thrownAnException(
				"Translation plural variable `{$varName}` has no `other` option defined."
			);
		return implode(' ', $sectionOptions);
	}

	/**
	 * Complete collection with matching brackets.
	 * @param  string $str   String to search brackets in.
	 * @param  string $begin Opening bracket char.
	 * @param  string $end   Closing bracket char.
	 * @return \array<\int,\int[]>
	 */
	protected static function getMatchingBracketsPositions ($str, $begin, $end) {
		$result = [];
		$i = 0;
		$l = mb_strlen($str);
		$matches = [];
		while ($i < $l) {
			$beginPos = mb_strpos($str, $begin, $i);
			$endPos = mb_strpos($str, $end, $i);
			$beginContained = $beginPos !== FALSE;
			$endContained = $endPos !== FALSE;
			if ($beginContained && $endContained) {
				if ($beginPos < $endPos) {
					$matches[] = [$begin, $beginPos];
					$i = $beginPos + 1;
				} else {
					$matches[] = [$end, $endPos];
					$i = $endPos + 1;
				}
			} else if ($beginContained) {
				$matches[] = [$begin, $beginPos];
				$i = $beginPos + 1;
			} else if ($endContained) {
				$matches[] = [$end, $endPos];
				$i = $endPos + 1;
			} else {
				break;
			}
		}
		if ($matches) {
			$level = 0;
			$levelItems = [];
			foreach ($matches as $item) {
				list($itemChar, $itemPos) = $item;
				$backSlashesCnt = 0;
				$backSlashPos = $itemPos - 1;
				while ($backSlashPos > -1) {
					$prevChar = mb_substr($str, $backSlashPos, 1);
					if ($prevChar == '\\') {
						$backSlashesCnt += 1;
						$backSlashPos -= 1;
					} else {
						break;
					}
				}
				$notBackSlashed = (
					$backSlashesCnt === 0 || (
					($backSlashesCnt > 0 && $backSlashesCnt % 2 === 0)
				));
				if ($notBackSlashed) {
					if ($itemChar == $begin) {
						if (!isset($levelItems[$level]))
							$levelItems[$level] = [];
						$levelItems[$level][] = [$itemPos]; // `{` position
						$level += 1;
					} else {
						$level -= 1;
						$levelItemsLen = count($levelItems[$level]);
						$lastLevelRec = & $levelItems[$level][$levelItemsLen - 1];
						$beginPos = $lastLevelRec[0];
						$lastLevelRec[1] = $itemPos; // `}` position
						$lastLevelRec[2] = NULL; // no subitems by default
						if (count($levelItems) > $level + 1) {
							$nextLevelRecords = array_slice($levelItems, $level + 1, NULL, TRUE);
							$nextLevelRecordsKeys = array_keys($nextLevelRecords);
							$nextLevelRecordsKeysLen = count($nextLevelRecordsKeys);
							$nextLevelRecordsLastKey = $nextLevelRecordsKeys[$nextLevelRecordsKeysLen - 1];
							$lastLevelRec[2] = $nextLevelRecords[$nextLevelRecordsLastKey];
							$levelItems = array_slice($levelItems, 0, $level + 1, TRUE);
						}
						$recordValue = mb_substr($str, $beginPos + 1, $itemPos - $beginPos - 1);
						$varName = NULL;
						if (preg_match("#^(\w+)#", $recordValue, $matches)) {
							$varName = $matches[1];
						}
						$lastLevelRec[3] = $varName;
						//$lastLevelRec[4] = $recordValue;
					}
				}
			}
			if (count($levelItems) > 0)
				$result = $levelItems[0];
		}
		return $result;
	}

	/**
	 * Thrown an exception with current translator class name.
	 * @param  string $msg
	 * @throws \Exception
	 * @return void
	 */
	protected static function thrownAnException ($msg) {
		$staticClass = PHP_VERSION_ID > 50500 ? static::class : get_called_class();
		throw new \Exception("[{$staticClass}] {$msg}");
	}

}
