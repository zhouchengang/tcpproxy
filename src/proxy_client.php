<?php

define('ROOT', __DIR__);

include ROOT . '/conf.php';
include ROOT . '/client.php';

for ($i = 2; $i < $proxy_conf['open_num']; $i++) {
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
		(new ProxyClient(
			$proxy_conf['public_ip'],
			$proxy_conf['public_proxy_port'],
			$proxy_conf['local_ip'],
			$proxy_conf['local_port']
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
	echo "child_exit|" . $child_pid . PHP_EOL;
}