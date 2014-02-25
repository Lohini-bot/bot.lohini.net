<?php // vim: ts=4 sw=4 ai:

use Nette\Application\Routers\Route,
	Nette\Utils\Json,
	Lohini\Utils\Network,
	Nette\Caching\Cache;

define('WWW_DIR', __DIR__);
define('ROOT_DIR', realpath(__DIR__.'/..'));

$umask=umask(0);

require_once ROOT_DIR.'/vendor/autoload.php';

require_once ROOT_DIR.'/libs/Configurator.php';
$configurator=new \Configurator;
$configurator->addConfig(ROOT_DIR.'/config/config.neon')
	->setTempDirectory($configurator->preparedParameters['tempdir']);
$container=$configurator->createContainer();
$configurator->enableDebugger($container->parameters['logdir'], $container->parameters['email']);

function json($msg, $status='ok') {
	return new \Nette\Application\Responses\JsonResponse(['status' => $status, $status => $msg]);
	}

// Setup router
$router=$container->getService('router');
$router[]=new Route('<server>/<command>', function ($server, $command) use ($container) {
	if (!isset($container->parameters[$server])) {
		return json('Invalid server.', 'error');
		}
	$req=$container->getService('httpRequest');
	if (!$req->isPost()) {
		return json('Please, use POST method.', 'error');
		}

	switch ($server) {
		case 'github':
			$cache=new Cache($container->getService('cacheStorage'), 'meta');
			if (!($data=$cache->offsetGet('github'))) {
				try {
					$req=new \Kdyby\Curl\Request($container->parameters['githubmeta']);
					$req->setUserAgent('firefox');
					$data=Json::decode($req->get()->getResponse(), Json::FORCE_ARRAY);
					}
				catch (\Exception $e) {
					$data['hooks']=['192.30.252.0/22'];
					}
				$cache->save(
					'github',
					$data,
					[
						Cache::CONSTS => [
							'Nette\Framework::REVISION',
							'Lohini\Framework::REVISION'
							],
						Cache::EXPIRE => 5*\Nette\DateTime::MINUTE
						]
					);
				}
			$valid=FALSE;
			$ip=Network::getRemoteIP();
			foreach ($data['hooks'] as $cidr) {
				if (Network::hostInCIDR($ip, $cidr)) {
					$valid=TRUE;
					break;
					}
				}
			if (!$valid) {
				return json('Invalid remote.', 'error');
				}
			if ($req->getHeader('x-github-event')==='ping') {
				return json('pong');
				}
			if ($req->getHeader('x-github-event')!=='push') {
				return json('Invalid data.', 'error');
				}
			$data=$req->getPost('payload');
			break;
		case 'gitlab':
			$data=file_get_contents('php://input');
			break;
		}

	try {
		$json=Json::decode($data, Json::FORCE_ARRAY);
		}
	catch (\Nette\Utils\JsonException $e) {
		return json('Invalid JSON motherfucker.', 'error');
		}
	if (!isset($json['repository']['url'])
		|| !isset($container->parameters[$server][$url=$json['repository']['url']])
		|| !isset($container->parameters[$server][$url][$command])
		) {
		return json('Invalid data.', 'error');
		}

	$file=tempnam(ROOT_DIR.'/bin/queue', time()."-$server-$command.");
	file_put_contents($file, Json::encode($json));
	chmod($file, 0666);

	return json('Thanks!');
	});
$router[]=new Route('/', function () use ($container) {
	if ($container->getService('httpRequest')->isPost()) {
		return json('Please specify a command', 'error');
		}
	$container->getService('httpResponse')->setContentType('text/plain');
	return new \Nette\Application\Responses\TextResponse('
                                  _____
                                 |     |
                                 | | | |
                                 |_____|   Please specify a command
                           ____ ___|_|___ ____
                          ()___)         ()___)
                          // /|           |\ \\\
                         // / |           | \ \\\
                        (___) |___________| (___)
                        (___)   (_______)   (___)
                        (___)     (___)     (___)
                        (___)      |_|      (___)
                        (___)  ___/___\___   | |
                         | |  |           |  | |
                         | |  |___________| /___\
                        /___\  |||     ||| //   \\\
                       //   \\\ |||     ||| \\\   //
                       \\\   // |||     |||  \\\ //
                        \\\ // ()__)   (__()
                              ///       \\\\\
                             ///         \\\\\
                           _///___     ___\\\\\_
                          |_______|   |_______|');
	});

// Configure and run the application!
$container->getService('application')->run();

umask($umask);
