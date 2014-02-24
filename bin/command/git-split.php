<?php // vim: ts=4 sw=4 ai:

use Nette\Utils\Validators;

/**
 * @param array $data
 * @param array $splits
 * @throws \Exception
 */
function git_split($data, array $splits)
{
	Validators::assertField($data, 'before', 'pattern:\w+');
	Validators::assertField($data, 'after', 'pattern:\w+');

	// working directory
	$repo=\Nette\Utils\Strings::webalize($url=$data['repository']['url']);
	$pwd=realpath(ROOT_DIR.'/repository');
	if (!file_exists($pwd.='/'.$repo)) {
		mkdir($pwd);
		}

	// console
	$cmd=function ($command, $arg=NULL) use ($pwd) {
		$args=func_get_args();
		array_shift($args); // command
		$command=preg_replace_callback(
			'~\%(\w)~',
			function ($m) use (&$args) {
				$arg=array_shift($args);
				return $m[1]==='l'? $arg : escapeshellarg($arg);
				},
			$command
			);

		exec(sprintf('cd %s && %s 2>&1', escapeshellarg($pwd), $command), $output, $status);
		if (0!==$status) {
			throw new \Exception("Error occured while executing: `$command`\n\n".implode("\n", $output));
			}
		debug("\$ `$command`", implode("\n", $output));
		return $output;
		};
	$git=function ($command, $arg=NULL) use ($cmd) {
		return call_user_func_array($cmd, ['git '.$command]+func_get_args());
		};

	// functions
	$fixRemotes=function ($requireRemotes=[], $actualRemotes=[]) use ($git, &$splits) {
		// parse splits
		foreach ($splits as $dir => $target) {
			$requireRemotes[$dir]=$target;
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

	$hasBranch=function ($branch) use ($git) {
		return in_array($branch, array_map(function ($b) { return trim(trim($b, '*')); }, $git('branch')));
		};

	$hasChanges=function ($dir, $before, $after) use ($git) {
		return (bool)$git('diff --stat %l..%l -- %s', $before, $after, $dir);
		};

	$split=function ($dir) use ($git) {
		$branch=$dir;
		$git('subtree split -q -P %s -b %l', $dir, $branch);
		$git('push %l %l:master', $branch, $branch);
		};

	$splitChanged=function ($before, $after) use ($git, $hasBranch, $hasChanges, &$splits, $split) {
		foreach ($splits as $dir => $target) {
			$git('fetch %l', $dir);
			if (!($hadBranch=$hasBranch($dir)) || $hasChanges($dir, $before, $after)) {
				if ($hadBranch) {
					$git('branch -D %l', $dir);
					}
				$split($dir);
				}
			}
		};

	if (!file_exists($pwd.'/.git')) {
		$git('clone %s .', $url);
		}

	// be carefull about uncommited changes
	if ($git('status --porcelain --untracked-files=no')) {
		throw new Exception('You have uncommitted changes');
		}

	// fetch changes
	$git('fetch origin');
	$git('checkout master');
	$git('pull origin master');

	// process
	$fixRemotes();
	$splitChanged($data['before'], $data['after']);
}
