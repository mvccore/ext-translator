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
 * Responsibility - basic CSV translator:
 *  - Not translated keys could be writen into store when request ends.
 *  - Translation value could contains basic integer or string 
 *    Replacements in curly brackets.
 *  - Translation value could contains i18n ICU translation format.
 */
abstract class AbstractTranslator implements \MvcCore\Ext\ITranslator {
	
	/**
	 * MvcCore Extension - Translator - version:
	 * Comparison by PHP function version_compare();
	 * @see http://php.net/manual/en/function.version-compare.php
	 */
	const VERSION = '5.0.1';

	/**
	 * Singleton instace for each localization.
	 * @var array<string,\MvcCore\Ext\Translators\AbstractTranslator>
	 */
	protected static $instances = [];

	/**
	 * Translator localization - it could be:
	 *  - lower case language code or 
	 *  - lower case language code + `_` + upper case locale code.
	 * @var string|NULL
	 */
	protected $localization = NULL;

	/**
	 * Optional cache instance to cache localized translations store.
	 * @var \MvcCore\Ext\ICache|NULL
	 */
	protected $cache = NULL;

	/**
	 * Translations store for current localication. 
	 * - Keys are translation keys.
	 * - Values are arrays with foloowing values:
	 *   - boolean if translation value is in i18nICU format
	 *   - string with translated term or instance with i18n ICU translation.
	 * @var array<string, [bool, string|\MvcCore\Ext\Translators\IcuTranslation]>|NULL
	 */
	protected $translations = NULL;

	/**
	 * Not translated keys to write in write background process handler after
	 * request end. This store contains not translated keys for all
	 * localizations for current request.
	 * @var array<string, bool>
	 */
	protected $newTranslations = [];
	
	/**
	 * Boolean about to write not translated keys in background process 
	 * handler after request end. It's always `TRUE` if environment is development.
	 * @var bool|NULL
	 */
	protected $writeNewTranslations = NULL;

	/**
	 * Boolean about if translations write background process handler after
	 * request end is already registered or not.
	 * @var bool
	 */
	protected $shutdownHandlerRegistered = FALSE;

	/**
	 * `TRUE` if Intl extension installed and `\MessageFormatter` class exists.
	 * @var bool
	 */
	protected $i18nIcuTranslationsSupported = FALSE;

	/**
	 * @inheritDocs
	 * @param  string $localization Translator localization - it could be:
	 *                              - lower case language code or 
	 *                              - lower case language code + `_` + upper case locale code.
	 * @return \MvcCore\Ext\Translators\AbstractTranslator
	 */
	public static function GetInstance ($localization) {
		if (isset(static::$instances[$localization]))
			return static::$instances[$localization];
		if (get_called_class() === __CLASS__) static::thrownAnException(
			"You need to extend abstract translator to desired implementation."
		);
		return static::$instances[$localization] = new static($localization);
	}
	
	/**
	 * Create new translator instance. To get translator 
	 * instance from outside, use static method:
	 * `\MvcCore\Ext\Translators\<Implementation>::GetInstance('en');`.
	 * @param  string $localization Translator localization - it could be:
	 *                              - lower case language code or 
	 *                              - lower case language code + `_` + upper case locale code.
	 * @return void
	 */
	protected function __construct ($localization) {
		$this->localization = $localization;
		if ($this->writeNewTranslations === NULL) {
			$environment = \MvcCore\Application::GetInstance()->GetEnvironment();
			$this->writeNewTranslations = $environment->IsDevelopment();
		}
		$this->i18nIcuTranslationsSupported = extension_loaded('intl') && class_exists('\\MessageFormatter');
	}

	/**
	 * @inheritDocs
	 * @param  string $localization Translator localization - it could be:
	 *                              - lower case language code or 
	 *                              - lower case language code + `_` + upper case locale code.
	 * @return \MvcCore\Ext\Translators\AbstractTranslator
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
	 * @param  \MvcCore\Ext\ICache|NULL $cache
	 * @return \MvcCore\Ext\Translators\AbstractTranslator
	 */
	public function SetCache (\MvcCore\Ext\ICache $cache = NULL) {
		$this->cache = $cache;
		return $this;
	}

	/**
	 * @inheritDocs
	 * @return \MvcCore\Ext\ICache|NULL
	 */
	public function GetCache () {
		return $this->cache;
	}
	
	/**
	 * @inheritDocs
	 * @param  string $key          A key to translate.
	 * @param  array  $replacements An array of replacements to process in translated result.
	 * @throws \Exception           En exception if translation is not successful.
	 * @return string				Translated key or key itself (if there is no key in translations store).
	 */
	public function Translate ($key, $replacements = []) {
		if ($this->translations === NULL)
			$this->translations = $this->GetStore(NULL);
		$i18nIcu = FALSE;
		if (array_key_exists($key, $this->translations)) {
			list($i18nIcu, $translation) = $this->translations[$key];
		} else {
			$this->addNewTranslation($key);
			$translation = ($this->writeNewTranslations ? static::NOT_TRANSLATED_KEY_MARK : '') . $key;
		}
		if ($this->i18nIcuTranslationsSupported && $i18nIcu) {
			/** @var \MvcCore\Ext\Translators\IcuTranslation $translation */
			if (!$translation->GetParsed())
				if (!$translation->Parse()) static::thrownAnException(
					"There were not possible to parse i18n ICU translation, "
					."key: `{$key}`, localization: `{$this->localization}`."
				);
			$translatedValue = $translation->Translate($replacements);
		} else if (is_string($translation)) {
			$translatedValue = $translation;
			if ($replacements !== NULL)
				foreach ($replacements as $key => $val)
					$translatedValue = str_replace('{'.$key.'}', (string) $val, $translatedValue);
		}
		return $translatedValue;
	}
	
	/**
	 * @inheritDocs
	 * @param  string $key			A key to translate.
	 * @param  array  $replacements	An array of replacements to process in translated result.
	 * @throws \Exception			En exception if translations store is not successful.
	 * @return string				Translated key or key itself (if there is no key in translations store).
	 */
	public function __invoke ($translationKey, $replacements = []) {
		return $this->Translate($translationKey, $replacements);
	}

	/**
	 * @inheritDocs
	 * @param  \int[]|\string[]|NULL $resourceIds,... Translation store resource id(s), optional.
	 * @throws \Exception
	 * @return array
	 */
	public function GetStore ($resourceIds = NULL) {
		$args = func_get_args();
		$resourceIds = is_array($args[0]) && count($args) === 1
			? $args[0]
			: $args;
		if ($this->writeNewTranslations || $this->cache === NULL)
			return $this->LoadStore($resourceIds);
		return $this->cache->Load(
			str_replace('<localization>', $this->localization, static::CACHE_KEY),
			function (\MvcCore\Ext\ICache $cache, $cacheKey) use ($resourceIds) {
				$result = $this->LoadStore($resourceIds);
				$cache->Save($cacheKey, $result, NULL, explode(',', static::CACHE_TAGS));
				return $result;
			}
		);
	}

	/**
	 * @inheritDocs
	 * @param  \int[]|\string[]|NULL $resourceIds,... Translation store resource id(s), optional.
	 * @throws \Exception
	 * @return array<string, string>
	 */
	public abstract function LoadStore ($storeIds = NULL);
	
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

	/**
	 * Detect if translated value is in i18n ICU format.
	 * @param  string $translationValue 
	 * @return bool
	 */
	protected function detectI18nIcuTranslation ($translationValue) {
		return (
			$this->i18nIcuTranslationsSupported && 
			\MvcCore\Ext\Translators\IcuTranslation::Detect($translationValue)
		);
	}

	/**
	 * Add not translated keys into translations stores after request is terminated.
	 * @param  string $translationKey
	 * @return void
	 */
	protected function addNewTranslation ($translationKey) {
		if (!$this->writeNewTranslations || $this->shutdownHandlerRegistered) return;
		$userAbortAllowed = $this->allowIgnoreUserAbort();
		$this->newTranslations[$translationKey] = TRUE;
		\MvcCore\Application::GetInstance()->AddPreSentHeadersHandler(
			function (\MvcCore\IRequest $req, \MvcCore\IResponse $res) use ($userAbortAllowed) {
				if (count($this->newTranslations) === 0 || $req->IsAjax()) return TRUE;
				if ($userAbortAllowed) {
					/**
					 * To not run translations write in real background process,
					 * comment following line, the line closes connection and also
					 * it kills any tracy debug output:
					 */
					$res
						->SetHeader('Connection', 'close')
						->SetHeader('Content-Length', strlen($res->GetBody()));
				}
				return TRUE;
			}
		);
		\MvcCore\Application::GetInstance()->AddPostTerminateHandler(
			function (\MvcCore\IRequest $req, \MvcCore\IResponse $res) use ($userAbortAllowed) {
				if ($req->IsAjax()) return TRUE;
				if (!$userAbortAllowed) {
					if (count($this->newTranslations) === 0) return;
					$this->writeTranslations();
				} else {
					// run in background processes:
					register_shutdown_function(
						function () {
							if (count($this->newTranslations) === 0) return;
							$this->writeTranslations();
						}
					);
				}
				return TRUE;
			}
		);
		$this->shutdownHandlerRegistered = TRUE;
	}

	/**
	 * Try to set `ignore_user_abort = On` by `ini_set()`.
	 * @return bool
	 */
	protected function allowIgnoreUserAbort () {
		$result = FALSE;
		$ignoreUserAbortIniVar = 'ignore_user_abort';
		$i = 0;
		while ($i < 2) {
			$ignoreUserAbort = @ini_get($ignoreUserAbortIniVar);
			if (!is_string($ignoreUserAbort)) break;
			if (strtolower($ignoreUserAbort) === 'on' || strtolower($ignoreUserAbort) === '1') {
				$result = TRUE;
				break;
			}
			@ini_set($ignoreUserAbortIniVar, 'On');
			$i++;
		}
		return $result;
	}

	/**
	 * Write not translated keys into primary resource, for current 
	 * localization, after request is done, when browser connection 
	 * is closed, in `register_shutdown_function()` handler.
	 * @return void
	 */
	protected function writeTranslations () {
		foreach (array_keys($this->newTranslations) as $newTranslation) {
				
		}
		// store not translated keys somewhere:
	}
}