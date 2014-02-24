<?php // vim: ts=4 sw=4 ai:
/**
 * This file is part of Lohini (http://lohini.net)
 *
 * @copyright (c) 2010, 2014 Lopo <lopo@lohini.net>
 */


if (PHP_VERSION_ID<50400) {
	throw new \Exception('Lohini Framework requires PHP 5.4 or newer.');
	}

/**
 * Initial system DI container generator
 *
 * @author Lopo <lopo@lohini.net>
 */
class Configurator
extends \Nette\Configurator
{
	/**
	 * @return array
	 */
	public function getPreparedParameters()
	{
		$loader=$this->createLoader();
		$config=[];
		foreach ($this->files as $tmp) {
			list($file, $section)=$tmp;
			try {
				if ($section===NULL) { // back compatibility
					$config=\Nette\DI\Config\Helpers::merge($loader->load($file, $this->parameters['environment']), $config);
					continue;
					}
				}
			catch (\Nette\InvalidStateException $e) {
				}
			catch (\Nette\Utils\AssertionException $e) {
				}

			$config=\Nette\DI\Config\Helpers::merge($loader->load($file, $section), $config);
			}
		if (!isset($config['parameters'])) {
			$config['parameters']=[];
			}
		$config['parameters']=\Nette\DI\Config\Helpers::merge($config['parameters'], $this->parameters);

		return \Nette\DI\Helpers::expand($config['parameters'], $config['parameters'], TRUE);
	}
}
