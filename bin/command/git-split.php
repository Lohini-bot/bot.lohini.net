<?php // vim: ts=4 sw=4 ai:

use Nette\Utils\Validators;

function git_split($data)
{
	Validators::assertField($data, 'before', 'pattern:\w+');
	Validators::assertField($data, 'after', 'pattern:\w+');

	// working directory
	$pwd=realpath(__DIR__.'/../../repository');

	// console
	$cmd=function($command, $arg=NULL) use ($pwd) {
		$args=func_get_args();
		array_shift($args); // command
		$command=preg_replace_callback(
			'~\%(\w)~',
			function($m) use (&$args) {
				$arg=array_shift($args);
				return $m[1]==='l'? $arg : escapeshellarg($arg);
				},
			$command
			);

		exec(sprintf('cd %s && %s 2>&1', escapeshellarg($pwd), $command), $output, $status);
		if (0!==$status) {
			throw new Exception("Error occured while executing: `$command`\n\n".implode("\n", $output));
			}
		debug("\$ `$command`", implode("\n", $output));
		return $output;
		};
	$git=function($command, $arg=NULL) use ($cmd) {
		return call_user_func_array($cmd, array('git '.$command)+func_get_args());
		};

	// functions
	$fixRemotes=function($requireRemotes=array(), $actualRemotes=array()) use ($git, &$splits) {
		// parse splits
		foreach ($splits as $dir => $meta) {
			$requireRemotes[$meta['branch']]=$meta['target'];
			}

		// fetch actual remotes
		foreach ($git('remote -v') as $remote) {
			if (preg_match('~^([^\s]+)\s+([^\s]+)\s+\((\w+)\)$~', $remote, $match) && $match[3]==='push') {
				$actualRemotes[$match[1]]=$match[2];
				}
			}

		// enforce remotes
		foreach (array_diff_assoc($requireRemotes, $actualRemotes) as $name => $target) {
			if (isset($actualRemotes[$name]) && $actualRemotes[$name]!==$target) {
				$git('remote rm %l', $name);
				}

			$git('remote add %l %s', $name, $target);
			}
		};

	$hasBranch=function($branch) use ($git) {
		return in_array($branch, array_map(function ($b) { return trim(trim($b, '*')); }, $git('branch')));
		};

	$hasChanges=function($dir, $before, $after) use ($git, &$splits) {
		$branch=$splits[$dir]['branch'];
		return (bool)$git('diff --stat %l..%l -- %s', $before, $after, $dir);
		};

	$split=function($dir) use ($git, &$splits) {
		$branch=$splits[$dir]['branch'];
		$git('subtree split -q -P %s -b %l', $dir, $branch);
		$git('push %l %l:master', $branch, $branch);
		};

	$splitChanged=function($before, $after) use ($git, $hasBranch, $hasChanges, &$splits, $split) {
		foreach ($splits as $dir => $meta) {
			$git('fetch %l', $meta['branch']);
			if (!($hadBranch=$hasBranch($meta['branch'])) || $hasChanges($dir, $before, $after)) {
				if ($hadBranch) {
					$git('branch -D %l', $meta['branch']);
					}
				$split($dir);
				}
			}
		};

	// be carefull about uncommited changes
	if ($git('status --porcelain --untracked-files=no')) {
		throw new Exception('You have uncommitted changes');
		}

	// fetch changes
	$git('fetch origin');
	$git('checkout master');
	$git('pull origin master');

	// parse required splits
	if (!file_exists($pwd.'/.split')) {
		throw new Exception('No .split file');
		}
	$splits=parse_ini_file($pwd.'/.split', TRUE);

	// process
	$fixRemotes();
	// $git('push origin master:master');
	$splitChanged($data['before'], $data['after']);
}
