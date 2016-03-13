<?php

define('ROOT', __DIR__);

include ROOT . '/client.php';
$sproxy_conf = include ROOT . '/conf.php';

if (0 == $sproxy_conf['daemon']) {
	$pid = pcntl_fork();
	if ($pid != 0) {
		exit(0);
	}
}

for ($i = 2; $i < $sproxy_conf['open_num']; $i++) {
	$pid = pcntl_fork();
	if ($pid == 0) {
		break;
	}
}

if ($pid == 0) {
	child:
	echo "fork_child|" . getmypid() . PHP_EOL;

	{
		//开启连接proxy_server
		(new SProxyClient(
			$sproxy_conf['public_ip'],
			$sproxy_conf['public_proxy_port'],
			$sproxy_conf['local_ip'],
			$sproxy_conf['local_port']
		))->run();
	}

	exit;
}

while (true) {
	$pid = pcntl_fork();
	if ($pid == 0) {
		//child
		goto child;
	}
	//parent
	$child_pid = pcntl_wait($status);
	//error
	if ($child_pid == -1) {
		break;
	}
	//避免proxy_server宕机造成不间断fork
	sleep(10);
	echo "child_exit|" . $child_pid . PHP_EOL;
}