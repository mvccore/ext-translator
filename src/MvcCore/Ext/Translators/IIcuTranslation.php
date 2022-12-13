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
interface IIcuTranslation {
	
	/**
	 * Return `TRUE` if translation value contains i18n ICU pattern.
	 * @param  string $translationValue 
	 * @return bool
	 */
	public static function Detect ($translationValue);

	/**
	 * Set translation localization (for example as `en` or `en-US` ...).
	 * @param string $localization	International language code in lower case or 
	 *								(international language code in lower case 
	 *								plus dash and plus international locale code 
	 *								in upper case).
	 * @return \MvcCore\Ext\Translators\IIcuTranslation
	 */
	public function SetLocalization ($localization);
	
	/**
	 * Get translation localization - it could be international language code in 
	 * lower case or (international language code in lower case plus dash and 
	 * plus international locale code in upper case).
	 * @return string
	 */
	public function GetLocalization ();

	/**
	 * Set i18n ICU translation pattern.
	 * @param string $pattern
	 * @return \MvcCore\Ext\Translators\IIcuTranslation
	 */
	public function SetPattern ($pattern);
	
	/**
	 * Get i18n ICU translation pattern.
	 * @return string
	 */
	public function GetPattern ();

	/**
	 * Set `TRUE` if pattern is modified to work properly with PHP `\MessageFormatter`.
	 * @param  bool $parsed 
	 * @return \MvcCore\Ext\Translators\IIcuTranslation
	 */
	public function SetParsed ($parsed);

	/**
	 * Get `TRUE` if pattern is modified to work properly with PHP `\MessageFormatter`.
	 * @return bool
	 */
	public function GetParsed ();
	
	/**
	 * Modify i18n ICU pattern to work properly with PHP `\MessageFormatter`.
	 * @throws \Exception
	 * @return bool
	 */
	public function Parse ();

	/**
	 * Call PHP `\MessageFormatter::format($replacements);`.
	 * @param  array $replacements 
	 * @return bool|string
	 */
	public function Translate (array $replacements = []);
}
