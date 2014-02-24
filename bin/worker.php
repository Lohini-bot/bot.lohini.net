#!/usr/bin/env php
<?php

use Nette\Diagnostics\Debugger,
	Nette\Utils\Strings,
	Nette\Utils\Json;

define('ROOT_DIR', realpath(__DIR__.'/..'));

$umask=umask(0);

require_once ROOT_DIR.'/vendor/autoload.php';

$parseOptions=function () use ($argc, $argv) {
	$options=[
		'config' => ROOT_DIR.'/config/config.neon'
		];
	foreach ($opts=getopt('c:e:l:p:t:', ['config', 'email', 'logdir', 'pidfile', 'tempdir']) as $arg => $val) {
		switch ($arg) {
			case 'c':
			case 'config':
				$options['config']=$val;
				break;
			case 'e':
			case 'email':
				$options['email']=$val;
				break;
			case 'l':
			case 'logdir':
				$options['logdir']=$val;
				break;
			case 'p':
			case 'pidfile':
				$options['pidfile']=$val;
				break;
			case 't':
			case 'tempdir':
				$options['tempDir']=$val;
				break;
			default:
				echo <<<HELP
Usage: {$argv[0]} [options]

Description:
  Processess Lohini bot jobs

Options:
    -c, --config          Use this config
    -e, --email           Email for error messages
    -l, --logdir          Dir to store logs
    -p, --pidfile         Where to write this process pid
    -t, --tempdir         Dir to store temp things
HELP;
				exit(0);
			}
		}

	$args=$argv;
	$pruneargv=[];
	foreach ($opts as $option => $value) {
		foreach ($args as $key => $chunk) {
			$regex='/^'.(isset($option[1])? '--' : '-').$option.'/';
			if ($chunk==$value && $args[$key-1][0]=='-' || preg_match($regex, $chunk)) {
				array_push($pruneargv, $key);
				}
			}
		}
	while ($key=array_pop($pruneargv)) {
		unset($args[$key]);
		}
	$args=array_merge($args);
	return $options;
	};

$options=$parseOptions();

require_once ROOT_DIR.'/libs/Configurator.php';
$configurator=new \Configurator;
$configurator->addConfig($options['config'])
	->setTempDirectory(isset($options['tempDir'])? $options['tempDir'] : $configurator->preparedParameters['tempdir']);
$parameters=$options+$configurator->createContainer()->parameters;
$configurator->enableDebugger($parameters['logdir'], $parameters['email']);
define('TEMP_DIR', realpath($parameters['tempDir']));

/**
 * @param string $server
 * @param string $command
 * @param array $data
 * @param array $settings
 */
function run($server, $command, $data=[], $settings, $conf=NULL)
{
	require_once __DIR__."/command/$command.php";
	$command=str_replace('-', '_', Strings::webalize($command));
	debug(str_repeat('-', 40), 'server: '.$server, 'command: '.$command, $data);
	return callback($command)->invoke($data, $settings, $conf);
}

/**
 * @param string $json
 * @return string
 */
function json_format($json)
{
    $tab='  ';
	$new_json='';
	$indent_level=0;
	$in_string=FALSE;
    $len=strlen($json);
    for ($c=0; $c<$len; $c++) {
        switch ($char=$json[$c]) {
            case '{':
			case '[':
                if (!$in_string) {
					$new_json.=$char."\n".str_repeat($tab, $indent_level+1);
					$indent_level++;
					}
                else {
					$new_json.=$char;
					}
                break;
            case '}':
			case ']':
                if (!$in_string) {
					$indent_level--;
					$new_json.="\n".str_repeat($tab, $indent_level).$char;
					}
                else {
					$new_json.=$char;
					}
                break;
            case ',':
				$new_json.= $in_string
					? $char
					: ",\n".str_repeat($tab, $indent_level);
                break;
            case ':':
				$new_json.= $in_string
					? $char
					: ': ';
                break;
            case '"':
                if ($c>0 && $json[$c-1]!='\\') {
					$in_string=!$in_string;
					}
            default:
                $new_json.=$char;
                break;
			}
		}

    return $new_json;
}

/**
 * @param mixed $e
 * @return string
 */
function debug($e)
{
	if ($e instanceof \Exception) {
		return Debugger::log($e);
		}

	$message=array_map(
		function ($message) {
			return !is_scalar($message)
				? json_format(Json::encode($message))
				: $message;
			},
		func_get_args()
		);

	file_put_contents(
		Debugger::$logDirectory.'/worker.log',
		rtrim(@date('[Y-m-d H-i-s]').' '.ltrim(Strings::indent(implode("\n", $message), 22, ' ')))."\n",
		FILE_APPEND
		);
}

function guardMemmory()
{
	static $last;
	$usage=memory_get_usage();
	if ($usage>$last) {
		$last=$usage;
		Debugger::log('Memory usage increased to '.number_format($usage/1000000, 2, '.', ' ').'MB', 'memory');
		}
}

function shiftQueue()
{
	$quests=glob(__DIR__.'/queue/*-*-*.*');
	natsort($quests);
	if ($nextQuest=array_shift($quests)) {
		try {
			$data=Json::decode(file_get_contents($nextQuest), Json::FORCE_ARRAY);
			$match=Strings::match($nextQuest, '/(?P<timestamp>\d+)-(?P<server>[^-]+)-(?P<command>[^ ]+)\./');
			}
		catch (\Nette\Utils\JsonException $e) {
			@copy($nextQuest, Debugger::$logDirectory.'/'.basename($nextQuest));
			@rename($nextQuest, __DIR__.'/error/'.basename($nextQuest));
			Debugger::log('Invalid json '.basename($nextQuest), 'error');
			return;
			}
		echo 'shifting ', basename($nextQuest), "\n";
		return [$match['server'], $match['command'], $data, $nextQuest];
		}
}

function work($params)
{
	try {
		if (list($server, $command, $data, $file)=shiftQueue()) {
			run($server, $command, $data, $params[$server][$data['repository']['url']][$command], $command=='apigen'? $params['apigen'] : NULL);
			}
		guardMemmory();
		@rename($file, __DIR__.'/done/'.basename($file));
		}
	catch (\Exception $e) {
		Debugger::log('Error while processing a message '.basename($file), 'error');
		Debugger::log($e);
		@copy($file, Debugger::$logDirectory.'/'.basename($file));
		@rename($file, __DIR__.'/error/'.basename($file));
		}
}

if (isset($parameters['pidfile'])) {
	file_put_contents($parameters['pidfile'], getmypid());
	$unlink=function () use ($parameters) {
		@unlink($parameters['pidfile']);
		};
	register_shutdown_function($unlink);
	foreach ([SIGTERM, SIGKILL, SIGINT] as $signal) {
		@pcntl_signal($signal, $unlink);
		}
	}

while (!sleep(1)) {
	work($parameters); // run !
	}

umask($umask);
