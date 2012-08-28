<?php // vim: ts=4 sw=4 ai:

use Nette\Application\Routers\Route,
	Nette\Utils\Json;

require_once __DIR__.'/../vendor/autoload.php';

$configurator=new \Nette\Config\Configurator;
$configurator->enableDebugger(__DIR__.'/../log', 'bot@lohini.net');
$configurator->setTempDirectory(__DIR__.'/../temp');
$container=$configurator->createContainer();

function json($msg, $status='ok') {
	return new \Nette\Application\Responses\JsonResponse(array('status' => $status, $status => $msg));
	}

// Setup router
$container->router[]=new Route('<command>', function($command) use ($container) {
	if ( ! $container->httpRequest->isPost()) {
		return json('Please, use POST method.', 'error');
		}

	$req=$container->httpRequest;
	if ($command==='git-split' && $req->getHeader('x-github-event')==='push') {
		try {
			$file=tempnam(__DIR__.'/../bin/queue', $command.'.');
			file_put_contents($file, Json::encode(Json::decode($req->getPost('payload'))));
			chmod($file, 0777);
			}
		catch (\Nette\Utils\JsonException $e) {
			return json('Invalid JSON motherfucker.', 'error');
			}
		}
	else {
		return json('Unknown command '.$command, 'error');
		}

	return json('Thanks!');
});
$container->router[]=new Route('/', function() use ($container) {
	return json('Please specify a command', 'error');
});

// Configure and run the application!
$container->application->run();
