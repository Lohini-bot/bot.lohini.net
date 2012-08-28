#!/usr/bin/env php
<?php

use Nette\Diagnostics\Debugger;
use Nette\Utils\Strings;
use Nette\Utils\Json;

require_once __DIR__ . '/../vendor/autoload.php';
Debugger::enable(FALSE, __DIR__ . '/../log', 'bot@lohini.net');
Debugger::$strictMode = TRUE;

function run($command, $data = array()) {
	require_once __DIR__ . '/command/' . $command . '.php';
	$command = str_replace('-', '_', Strings::webalize($command));
	debug(str_repeat('-', 40), 'command: ' . $command, $data);
	return callback($command)->invoke($data);
}

function json_format($json) {
    $tab = "  "; $new_json = ""; $indent_level = 0; $in_string = false;
    $len = strlen($json);
    for($c = 0; $c < $len; $c++) {
        $char = $json[$c];
        switch($char) {
            case '{': case '[':
                if(!$in_string)  { $new_json .= $char . "\n" . str_repeat($tab, $indent_level+1); $indent_level++; }
                else $new_json .= $char;
                break;
            case '}': case ']':
                if(!$in_string) { $indent_level--; $new_json .= "\n" . str_repeat($tab, $indent_level) . $char; }
                else $new_json .= $char;
                break;
            case ',':
                if(!$in_string) $new_json .= ",\n" . str_repeat($tab, $indent_level); else $new_json .= $char;
                break;
            case ':':
                if(!$in_string) $new_json .= ": "; else $new_json .= $char;
                break;
            case '"':
                if($c > 0 && $json[$c-1] != '\\') $in_string = !$in_string;
            default:
                $new_json .= $char;
                break;
        }
    }

    return $new_json;
}

function debug($e) {
	if ($e instanceof \Exception) return Debugger::log($e);

	$message = array_map(function ($message) {
		return !is_scalar($message) ? json_format(Json::encode($message)) : $message;
	}, func_get_args());
	$message = @date('[Y-m-d H-i-s]') . " " . ltrim(Strings::indent(implode("\n", $message), 22, " "));

	file_put_contents(Debugger::$logDirectory . '/worker.log', rtrim($message) . "\n", FILE_APPEND);
}

function guardMemmory() {
	static $last;
	$usage = memory_get_usage();
	if ($usage > $last) {
		$last = $usage;
		Debugger::log("Memory usage increased to " . number_format($usage / 1000000, 2, '.', ' ') . 'MB', 'memory');
	}
}

function shiftQueue() {
	$quests = glob(__DIR__ . '/queue/*.*');
	natsort($quests);
	if ($nextQuest = array_shift($quests)) {
		try {
			$data = Json::decode(file_get_contents($nextQuest), Json::FORCE_ARRAY);
		} catch (Nette\Utils\JsonException $e) {
			@copy($nextQuest, Debugger::$logDirectory . '/' . basename($nextQuest));
			@unlink($nextQuest);
			Debugger::log("Invalid json " . basename($nextQuest), 'error');
			return;
		}
		echo "shifting ", basename($nextQuest), "\n";
		return array(pathinfo($nextQuest, PATHINFO_FILENAME), $data, $nextQuest);
	}
}

function work() {
	try {
		if (list($command, $data, $file) = shiftQueue()) run($command, $data);
		guardMemmory();
		@unlink($file);

	} catch (\Exception $e) {
		Debugger::log("Error while processing a message " . basename($file), 'error');
		Debugger::log($e);
		@copy($file, Debugger::$logDirectory . '/' . basename($file));
		@unlink($file);
	}
}

while (!sleep(1)) work(); // run !
