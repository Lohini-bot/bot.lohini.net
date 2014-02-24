<?php // vim: ts=4 sw=4 ai:

use Nette\Application\Routers\Route,
	Nette\Utils\Json;

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
