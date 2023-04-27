<?php

namespace ADT\PresenterTestCoverage;

use Nette\Loaders\RobotLoader;
use Nette\Utils\Strings;


class ComponentCoverageException extends \Exception {

}


class Service
{

	protected array $config = [];

	protected ?RobotLoader $robotLoader = null;

	/**
	 * @var array
	 * Array of Arrays -> obsahuje informace o komponentach, ktere mame kontrolovat
	 */
	private $coveredComponents = [];

	/**
	 * @var array
	 * Pole obsahujici jemna komponent ktere nejsou dobre nakonfigurovane a neni mozne je otestovat
	 */
	private $skippedForMissingConfiguration = [];


	/**
	 * @var array testy, ktere se nam podarilo najit v prislusne slozce
	 */
	private $existingTests = [];

	/**
	 * @var array obsahuje soupis testu, ktere pokryvaji metody, ktere mame na projektu
	 */
	private $foundTests = [];

	/**
	 * @var array obsahuje soupis testu, ktere je treba vytvorit aby doslo k pokryti vsech zadanych slozek
	 */
	private $missingTests = [];

	/**
	 * @var array obsahuje vsechny metody, ktere maji byt pokryty testy
	 */
	private $methodsToCover = [];

	/**
	 * @param array $config
	 */
	public function setConfig(array $config = []): self
	{
		$this->config = $config;
		return $this;
	}


	/**
	 * Vraci pole obsahujici nalezene testovaci metody pokryvajici zadanou slozku
	 * @throws \ReflectionException
	 */
	public function getFoundMethods(): array
	{
		//pokud je pole prazdne, je sance ze se jeste nic nehledalo -> prohledame
		if (empty($this->foundTests)) {
			$this->findExistingTests();
			$this->checkCoverage();
		}
		return $this->foundTests;
	}


	/**
	 * Vraci pole obsahujici testovaci metody, ktere se nepodarilo nalezt a jsou treba k pokryti
	 * @throws \ReflectionException
	 */
	public function getMissingMethods(): array
	{
		//prazdne pole -> radeji prohledame
		if (empty($this->missingTests)) {
			$this->findExistingTests();
			$this->checkCoverage();
		}
		return $this->missingTests;
	}


	/**
	 * Kontrola zda se jedna o metodu, ktera je urcena nastavenim k otestovani.
	 * @param string $methodName
	 * @param string|null $prefix
	 */
	protected static function isMethodToTest(string $methodName, string $prefix = null) : bool
	{

		return self::matchesMask($methodName, $prefix);

		if (Strings::startsWith($methodName, $prefix)) {
			return true;
		}
		return false;
	}


	/**
	 * metoda kontroluje pokryti metod testy.
	 * @throws \ReflectionException
	 */
	protected function checkCoverage(): void {
		$cwd = getcwd().'/';
		if (!empty($this->foundTests) || !empty($this->missingTests)) {
			return;
		}

		$this->findMethodsToCover();
		foreach ($this->methodsToCover as $key => $method) {

			//ziskame cast namespace podle ktere porovnavame
			$position = strpos($method, '\\');
			if ($position) {
				$method = substr($method, $position + 1);
			}

			if (array_key_exists($method, $this->existingTests)) {
				$this->foundTests[] = str_replace($cwd, '', $this->existingTests[$method]);
			} else {
				$this->missingTests[] = str_replace($cwd, '', $key);
			}
		}
	}


	/**
	 * Nacteni konfigurace pro vyheldavani a nacteni vsech souboru ktere jsou zadany
	 */
	public function getRobotLoader(): RobotLoader
	{
		if (!$this->robotLoader) {

			//nelze pracovat bez zadane temp slozky -> vyhozeni vyjimky
			if(!array_key_exists('tempDir', $this->config) || !isset($this->config['tempDir'])) {
				throw new ComponentCoverageException('Missing tempDir in presenterTestCoverage:');
			}

			$this->robotLoader = (new RobotLoader)
				->setTempDirectory($this->config['tempDir']);

			//nelze pracovat bez zadane slozky testu -> vyhozeni vyjimky
			if(!array_key_exists('testDir', $this->config) || !isset($this->config['testDir'])) {
				throw new ComponentCoverageException('Missing testDir in presenterTestCoverage:');
			}

			// iterace pres vsechny nakonfigurovane slozky
			foreach ($this->config['componentCoverage'] as $key => $dirSetup) {

				/*
				 * Kontroloa ze mame nakonfigurovany vsechny potrebne udaje, pokud neco chybi, dana cast se nezpracuje
				 * a uzivatel je informovat o tom co chybi.
				 */

				if (!array_key_exists('componentDir', $dirSetup)) {
					$this->skippedForMissingConfiguration[] = 'componentDir in '.$key;
					continue;
				}

				if (!array_key_exists('fileMask', $dirSetup)) {
					$this->skippedForMissingConfiguration[] = 'fileMask in '.$key;
					continue;
				}

				if (!array_key_exists('methodMask', $dirSetup)) {
					$this->skippedForMissingConfiguration[] = 'methodMask in '.$key;
					continue;
				}

				// nastaveni ktere slozky se budou indexovat/
				$this->robotLoader->addDirectory($dirSetup['componentDir']);
				$this->robotLoader->addDirectory($this->config['testDir']);

				// nastavime si pro ktere vsechny slozky budeme pracovat
				$this->coveredComponents[] = ["dir" => $dirSetup['componentDir'], "section" => $key];
			}
		}
		return $this->robotLoader;
	}


	/**
	 * Pouze getter, vraci informace o selhani nastaveni konfigurace
	 */
	public function getSkippedForMissingConfiguration(): array {
		return $this->skippedForMissingConfiguration;
	}


	/**
	 * Metoda vyhleda vsechny soubory podle zadane masky a nastavi je do pole
	 * @throws \ReflectionException
	 */
	protected function findMethodsToCover(): void {

		if (!empty($this->methodsToCover)) {
			return;
		}

		foreach ($this->getRobotLoader()->getIndexedClasses() as $_className => $_classFile) {

			// kontrola jestli se jedna o neco co ma byt pokryto testy
			$notFound = true;
			$section = '';
			foreach ($this->coveredComponents as $key => $componentDir) {
				// kontrola jestli dany soubor je soucasti souboru, ktere obsahuji implementaci pro kterou by mel existovat test.
				if (! Strings::startsWith($_classFile, $componentDir['dir'] . '/')) {
					continue;
				}
				// nalezen, nastavime notFound na false a ukoncime iterovani
				$notFound = false;
				$section = $componentDir['section'];
				break;
			}

			// pokud jsme dany soubor nenasli, budeme pokracovat dalsi iteraci
			if ($notFound) {
				continue;
			}

			$mask = $this->config['componentCoverage'][$section]['fileMask'];



			if (! self::matchesMask($_classFile, $mask)) {
				continue;
			}

			require_once $_classFile;

			$_presenterReflection = new \ReflectionClass($_className);

			// nechceme zpracovavat abstraktni tridy -> continue
			if ($_presenterReflection->isAbstract()) {
				continue;
			}

			// maska metody, chceme zpracovat jenom metody ktere maji danou masku
			$methodMask = null;
			if (isset($this->config['componentCoverage'][$section]['methodMask'])) {
				$methodMask = $this->config['componentCoverage'][$section]['methodMask'];
			}

			foreach ($_presenterReflection->getMethods() as $_presenterMethodReflection) {
				// testy na abstraktni metody nemaji smysl -> continue
				if ($_presenterMethodReflection->isAbstract()) {
					continue;
				}

				if (! static::isMethodToTest($_presenterMethodReflection->getName(), $methodMask)) {
					continue;
				}

				// potrebujeme ziskat cestu k souboru v mistni slozce, ta odpovida namespace -> smazeme to co je pred namespacem
				$postionoOfNamespace = stripos($_presenterReflection->getFileName(), str_replace('\\', '/', $_presenterReflection->getName()));

				//bude treba odstranit prvni element z namespace
				$filePath = substr($_presenterReflection->getFileName(), $postionoOfNamespace);
				$filePath = substr($filePath, (strpos( $filePath, '/') + 1));

				//plna cesta k testovacimu souboru
				$testFilePath = realpath($this->config['testDir']). '/' .$filePath."::".$_presenterMethodReflection->getName();

				//vytvareni soupisu metod pro ktere budeme chtit hledat testy
				$this->methodsToCover[$testFilePath] = $_presenterReflection->getName()."::".$_presenterMethodReflection->getName();
			}
		}
	}


	/**
	 * metoda najde vsechny dostupne testy, ktere mame a ulozi si je
	 */
	protected function findExistingTests(): void {

		// pokud se jiz jednou sestavilo pole testu, neni treba hledat -> return
		if(!empty($this->existingTests)){
			return;
		}

		$testDir = $this->config['testDir'];

		foreach ($this->getRobotLoader()->getIndexedClasses() as $_className => $_classFile) {
			$namespaceArray = null;

			$crawlerBasePath = realpath($testDir) . '/';

			//pokud nejsme v testovaci slozce, soubor neni treba kontrolovat
			if (! Strings::startsWith($_classFile, $crawlerBasePath)) {
				continue;
			}

			//mame cestu k souboru, protoze namespace odpovida ceste k souboru, mame v podstate namespace bez prvniho elementu
			$classFileRootedPath = str_replace($crawlerBasePath, '', $_classFile);

			require_once $_classFile;

			$_crawlerReflection = new \ReflectionClass($_className);

			if ($_crawlerReflection->isAbstract()) {
				continue;
			}

			foreach ($_crawlerReflection->getMethods() as $_crawlerMethodReflection) {

				if ($_crawlerMethodReflection->isAbstract()) {
					continue;
				}

				//konverze nazvu na "kratky namespace"
				$shortNamespace = substr($classFileRootedPath, 0, strrpos($classFileRootedPath, '.'));

				$this->existingTests[str_replace('/', '\\',$shortNamespace)."::".$_crawlerMethodReflection->getName()] = $_classFile."::".$_crawlerMethodReflection->getName();
			}
		}
	}


	/**
	 * Kontrola jestli zadany string odpovida masce
	 * @param string $haystack
	 * @param string $mask
	 */
	protected static function matchesMask(string $haystack, string $mask): bool {
		if ($mask === '*') {
			$mask = '.*';
		}

		return preg_match('/'.$mask.'/', $haystack) ? true : false;
	}

}