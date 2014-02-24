<?php // vim: ts=4 sw=4 ai:

/**
 * @param array $data
 * @param array $gens
 * @param string $options
 * @throws \Exception
 */
function apigen($data, array $gens, $options)
{
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
	$apigen=function ($command, $arg=NULL) use ($cmd) {
		return call_user_func_array($cmd, [escapeshellarg(ROOT_DIR.'/vendor/bin/apigen.php').' '.$command]+func_get_args());
		};

	$generateApi=function () use ($cmd, $apigen, $gens, $pwd, $data, $options) {
		foreach ($gens as $src => $dst) {
			$cmd('rm -rf %s', TEMP_DIR.'/apigen');
			mkdir(TEMP_DIR.'/apigen');
			if ($apigen('-s %s -d %s --title %s %l', $pwd.'/'.$src, TEMP_DIR.'/apigen', $data['repository']['name'], $options)
				&& file_exists(TEMP_DIR.'/apigen/index.html')
				) {
				$cmd('rm -rf %s', $dst);
//				rename(TEMP_DIR.'/apigen', $dst);
				$cmd('mv %s %s', TEMP_DIR.'/apigen', $dst);
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
	$generateApi();
}
