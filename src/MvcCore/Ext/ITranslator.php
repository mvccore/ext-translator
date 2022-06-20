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

namespace MvcCore\Ext;

/**
 * Responsibility - basic CSV translator:
 *  - Not translated keys could be writen into store when request ends.
 *  - Translation value could contains basic integer or string 
 *    Replacements in curly brackets.
 *  - Translation value could contains i18n ICU translation format.
 */
interface ITranslator {

	/**
	 * Character to add before translation key, if record is not translated yet.
	 * @var string
	 */
	const NOT_TRANSLATED_KEY_MARK = '+';
	
	/**
	 * Cache key, substring `<localization>` is replaced with `$this->localization` value.
	 * @var string
	 */
	const CACHE_KEY = 'translations_<localization>';
	
	/**
	 * Cache tags
	 * @var string
	 */
	const CACHE_TAGS = 'localization,translator';
	
    /**
	 * Get translator instance by localization key (for example: `en`, `en-US`).
	 * @param string $localization	International language code in lower case or 
	 *								(international language code in lower case 
	 *								plus dash and plus international locale code 
	 *								in upper case).
	 * @return \MvcCore\Ext\ITranslator
	 */
	public static function GetInstance ($localization);

	/**
	 * Set translator localization (for example as `en` or `en-US` ...).
	 * @param string $localization	International language code in lower case or 
	 *								(international language code in lower case 
	 *								plus dash and plus international locale code 
	 *								in upper case).
	 * @return \MvcCore\Ext\ITranslator
	 */
	public function SetLocalization ($localization);

	/**
	 * Get translator localization - it could be international language code in 
	 * lower case or (international language code in lower case plus dash and 
	 * plus international locale code in upper case).
	 * @return string
	 */
	public function GetLocalization ();

	/**
	 * Set optional cache instance.
	 * @param  \MvcCore\Ext\ICache|NULL $cache
	 * @return \MvcCore\Ext\ITranslator
	 */
	public function SetCache (\MvcCore\Ext\ICache $cache = NULL);

	/**
	 * Get optional cachce instance.
	 * @return \MvcCore\Ext\ICache|NULL
	 */
	public function GetCache ();

	/**
	 * Translate given key into target localization. If there is no translation
	 * for given key in translations data, there is returned given key with plus sign.
	 * @param  string $key          A key to translate.
	 * @param  array  $replacements An array of replacements to process in translated result.
	 * @throws \Exception           En exception if translation is not successful.
	 * @return string               Translated key or key itself if there is no key in translations store.
	 */
	public function Translate ($key, $replacements = []);
	
	/**
	 * Basic translation view helper implementation by `__invoke()` megic method.
	 * Please register translation view helper more better by anonymous closure
	 * function with `$this->Translate()` function call inside. It's much faster.
	 * to handle view helper calls to translate strings.
	 * @param  string $key          A key to translate.
	 * @param  array  $replacements An array of replacements to process in translated result.
	 * @throws \Exception           En exception if translation is not successful.
	 * @return string               Translated key or key itself if there is no key in translations store.
	 */
	public function __invoke ($key, $replacements = []);

	/**
	 * If there is cache configured and environment is not development,
	 * get store from cache. If there is no cache record, load store from
	 * primary resources.
	 * If there is no cache defined or environment is development, load 
	 * store always from primary resources.
	 * @param  \int[]|\string[]|NULL $resourceIds,... Translation store resource id(s), optional.
	 * @throws \Exception
	 * @return array<string, string>
	 */
	public function GetStore ($resourceIds = NULL);

	/**
	 * Load translation store from primary resource(s).
	 * @param  \int[]|\string[]|NULL $resourceIds,... Translation store resource id(s), optional.
	 * @throws \Exception
	 * @return array<string, string>
	 */
	public function LoadStore ($resourceIds = NULL);
}