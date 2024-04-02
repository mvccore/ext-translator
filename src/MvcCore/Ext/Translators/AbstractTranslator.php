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
	const VERSION = '5.2.6';

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
	 * Translation store default resource ids. Optional.
	 * @var \int[]|\string[]
	 */
	protected $resourceIds = [];

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
	 * New translation keys to write in background process handler after
	 * request end. This store contains not translated keys for current
	 * localization for current request. Key is translation key, value
	 * is translation text source file and line.
	 * @var array<string, string>
	 */
	protected $newTranslations = [];

	/**
	 * Used translation keys to update in background process handler after
	 * request end. This store contains already translated keys for current
	 * localization for current request. Key is translation key, value
	 * is translation text source file and line.
	 * @var array<string, string>
	 */
	protected $usedTranslations = [];
	
	/**
	 * Boolean about to write not translated keys in background process 
	 * handler after request end. It's always `TRUE` if environment is development.
	 * @var bool|NULL
	 */
	protected $writeTranslations = NULL;

	/**
	 * Integer state about registration of background process handler 
	 * to write new translations or update existing.
	 *  - 0 - shutdown handler is not registered
	 *  - 1 - shutdown handler is registered, but not necessary to call
	 *  - 2 - shutdown handler is registered and necessary to call
	 * @var int
	 */
	protected $shutdownHandlerRegistered = 0;

	/**
	 * `TRUE` if Intl extension installed and `\MessageFormatter` class exists.
	 * @var bool
	 */
	protected $i18nIcuTranslationsSupported = FALSE;

	/**
	 * @inheritDoc
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
		if ($this->writeTranslations === NULL) {
			$environment = \MvcCore\Application::GetInstance()->GetEnvironment();
			$this->writeTranslations = $environment->IsDevelopment();
		}
		$this->i18nIcuTranslationsSupported = extension_loaded('intl') && class_exists('\\MessageFormatter');
	}

	/**
	 * @inheritDoc
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
	 * @inheritDoc
	 * @return string
	 */
	public function GetLocalization () {
		return $this->localization;
	}
	
	/**
	 * @inheritDoc
	 * @param  \MvcCore\Ext\ICache|NULL $cache
	 * @return \MvcCore\Ext\Translators\AbstractTranslator
	 */
	public function SetCache (\MvcCore\Ext\ICache $cache = NULL) {
		$this->cache = $cache;
		return $this;
	}

	/**
	 * @inheritDoc
	 * @return \MvcCore\Ext\ICache|NULL
	 */
	public function GetCache () {
		return $this->cache;
	}
	
	/**
	 * @inheritDoc
	 * @param   array<int|string|NULL> $resourceIds Translation store resource id(s) to be merged.
	 * @return \MvcCore\Ext\Translators\AbstractTranslator
	 */
	public function AddResourceIds (array $resourceIds) {
		if (count($resourceIds) > 0)
			$this->resourceIds = array_unique(array_merge($this->resourceIds, $resourceIds));
		return $this;
	}

	/**
	 * @inheritDoc
	 * @param   array<int|string|NULL> $resourceIds Translation store resource id(s) to be replaced.
	 * @return \MvcCore\Ext\Translators\AbstractTranslator
	 */
	public function SetResourceIds (array $resourceIds) {
		$this->resourceIds = $resourceIds;
		return $this;
	}

	/**
	 * @inheritDoc
	 * @return array<int|string|NULL>
	 */
	public function GetResourceIds () {
		return $this->resourceIds;
	}
	
	/**
	 * @inheritDoc
	 * @return string|NULL
	 */
	public function GetStoreCacheKey () {
		if ($this->cache === NULL) return NULL;
		// try to get store from cache:
		$resourceIdsStr = count($this->resourceIds) > 0 
			? '[' . implode(',', $this->resourceIds) . ']' 
			: '[]';
		$cacheKey = str_replace(
			['<localization>', '<resourceIds>'], 
			[$this->localization, $resourceIdsStr], 
			static::CACHE_KEY
		);
		return $cacheKey;
	}

	/**
	 * @inheritDoc
	 * @param  string $key          A key to translate.
	 * @param  array  $replacements An array of replacements to process in translated result.
	 * @throws \Exception           En exception if translation is not successful.
	 * @return string				Translated key or key itself (if there is no key in translations store).
	 */
	public function Translate ($key, $replacements = []) {
		if ($this->translations === NULL) 
			$this->translations = $this->GetStore();
		$i18nIcu = FALSE;
		if (array_key_exists($key, $this->translations ?: [])) {
			$storeRec = & $this->translations[$key];
			list($i18nIcu, $translation) = $storeRec;
			if ($this->i18nIcuTranslationsSupported && $i18nIcu) {
				if (is_string($translation)) 
					$translation = new \MvcCore\Ext\Translators\IcuTranslation(
						$this->localization, $translation
					);
				if (!$translation->GetParsed() && !$translation->Parse()) 
					static::thrownAnException(
						"There were not possible to parse i18n ICU translation, "
						."key: `{$key}`, localization: `{$this->localization}`."
					);
				$storeRec[1] = $translation;
			}
			if ($this->writeTranslations)
				$this->updateUsedTranslation($key);
			if ($this->i18nIcuTranslationsSupported && $i18nIcu && count($replacements) === 0)
				return $translation->GetPattern();
		} else {
			if ($this->writeTranslations)
				$this->addNewTranslation($key);
			$translation = ($this->writeTranslations ? static::NOT_TRANSLATED_KEY_MARK : '') . $key;
		}
		return $this->translateReplacements($key, $translation, $i18nIcu, $replacements);
	}

	/**
	 * Process translation replacements.
	 * @param  string                                         $key 
	 * @param  string|\MvcCore\Ext\Translators\IcuTranslation $translation 
	 * @param  bool|string                                    $i18nIcu 
	 * @param  array                                          $replacements 
	 * @throws \Exception           En exception if translation is not successful.
	 * @return array|bool|string
	 */
	protected function translateReplacements ($key, $translation, $i18nIcu, $replacements = []) {
		if ($this->i18nIcuTranslationsSupported && $i18nIcu) {
			/** @var \MvcCore\Ext\Translators\IcuTranslation $translation */
			$translatedValue = $translation->Translate($replacements);
		} else {
			$translatedValue = (string) $translation;
			if (count($replacements) > 0)
				foreach ($replacements as $replKey => $replVal)
					$translatedValue = str_replace('{'.$replKey.'}', (string) $replVal, $translatedValue);
		}
		return $translatedValue;
	}
	
	/**
	 * @inheritDoc
	 * @param  string $key			A key to translate.
	 * @param  array  $replacements	An array of replacements to process in translated result.
	 * @throws \Exception			En exception if translations store is not successful.
	 * @return string				Translated key or key itself (if there is no key in translations store).
	 */
	public function __invoke ($translationKey, $replacements = []) {
		return $this->Translate($translationKey, $replacements);
	}

	/**
	 * @inheritDoc
	 * @throws \Exception
	 * @return array
	 */
	public function GetStore () {
		// load translations on dev env or when cache is not available:
		if ($this->writeTranslations || $this->cache === NULL)
			return $this->LoadStore();
		// try to get store from cache:
		return $this->cache->Load(
			$this->GetStoreCacheKey(),
			function (\MvcCore\Ext\ICache $cache, $cacheKey) {
				$result = $this->LoadStore();
				$cache->Save($cacheKey, $result, NULL, explode(',', static::CACHE_TAGS));
				return $result;
			}
		);
	}

	/**
	 * @inheritDoc
	 * @throws \Exception
	 * @return array<string, string>
	 */
	public abstract function LoadStore ();
	
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
	 * Add not translated key into translations stores after request is terminated.
	 * @param  string $translationKey
	 * @return void
	 */
	protected function addNewTranslation ($translationKey) {
		$this->newTranslations[$translationKey] = $this->getTranslationSource(
			$translationKey, \MvcCore\Application::GetInstance()->GetRequest()->GetAppRoot()
		);
		if ($this->shutdownHandlerRegistered) return;
		$this->registerShutdownHandler();
		$this->shutdownHandlerRegistered = 2;
	}
	
	/**
	 * Update used translation keys in translations stores after request is terminated.
	 * @param  string $translationKey
	 * @return void
	 */
	protected function updateUsedTranslation ($translationKey) {
		$this->usedTranslations[$translationKey] = $this->getTranslationSource(
			$translationKey, \MvcCore\Application::GetInstance()->GetRequest()->GetAppRoot()
		);
		if ($this->shutdownHandlerRegistered) return;
		$this->registerShutdownHandler();
		$this->shutdownHandlerRegistered = 2;
	}
	
	/**
	 * Register shutdown handler to write new translations or update used translations.
	 * @return void
	 */
	protected function registerShutdownHandler () {
		$userAbortAllowed = $this->allowIgnoreUserAbort();
		$app = \MvcCore\Application::GetInstance();
		$app->AddPreSentHeadersHandler(
			function (\MvcCore\IRequest $req, \MvcCore\IResponse $res) use ($app, $userAbortAllowed) {
				if ($this->shutdownHandlerRegistered === 1) return TRUE;
				if ($userAbortAllowed) {
					/**
					 * Connection could be closed only if debugging is not enabled,
					 * it means no debug bar is not appended after sent content.
					 */
					$debugClass = $app->GetDebugClass();
					if (!$debugClass::GetDebugging())
					$res
						->SetHeader('Connection', 'close')
						->SetHeader('Content-Length', strlen($res->GetBody()));
				}
				return TRUE;
			}
		);
		$app->AddPostTerminateHandler(
			function (\MvcCore\IRequest $req, \MvcCore\IResponse $res) use ($userAbortAllowed) {
				if ($this->shutdownHandlerRegistered === 1) return;
				if (!$userAbortAllowed) {
					$this->writeTranslationsHandler();
				} else {
					// run in background processes:
					register_shutdown_function(
						function () {
							$this->writeTranslationsHandler();
						}
					);
				}
				return TRUE;
			}
		);
		$this->shutdownHandlerRegistered = 1;
	}
	/**
	 * Complete translation source file:line from `debug_backtrace()`.
	 * @param  string $translationKey
	 * @param  string $appRoot
	 * @return string
	 */
	protected function getTranslationSource ($translationKey, $appRoot) {
		$translationSource = '';
		$debugBacktraceItems = debug_backtrace();
		$lastItemWithTransArg = -1;
		foreach ($debugBacktraceItems as $index => $debugBacktraceItem) {
			if ($index < 2) continue;
			$args = $debugBacktraceItem['args'];
			$argsCount = count($args);
			if ($argsCount === 0 && $lastItemWithTransArg > -1) break;
			if ($argsCount > 0) {
				$func = $debugBacktraceItem['function'];
				if ($func === 'call_user_func_array' || $func === '__call') {
					$args = $args[1];
				} else if ($func === 'call_user_func') {
					array_shift($args);
				}
				if ($args[0] === $translationKey)
					$lastItemWithTransArg = $index;
			}
		}
		if ($lastItemWithTransArg > -1) {
			$debugBacktraceItem = $debugBacktraceItems[$lastItemWithTransArg];
			if (isset($debugBacktraceItem['file']) && isset($debugBacktraceItem['line'])) {
				$file = ucfirst(str_replace('\\', '/', $debugBacktraceItem['file']));
				if (mb_strpos($file, $appRoot . '/') === 0)
					$file = '.' . mb_substr($file, mb_strlen($appRoot));
				$translationSource = $file . ':' . $debugBacktraceItem['line'];
			}
		}
		/*if ($translationSource === '') {
			x($debugBacktraceItems);
		}*/
		unset($debugBacktraceItems);
		return $translationSource;
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
	protected function writeTranslationsHandler () {
		foreach (array_keys($this->newTranslations) as $newTranslation) {
				
		}
		// store not translated keys somewhere:
	}
}