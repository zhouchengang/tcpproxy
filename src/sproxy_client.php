<?php

define('ROOT', __DIR__);

include ROOT . '/client.php';
$sproxy_conf = [
	'daemon' => 0,
	'process_num' => 10,
	//指定代理服务地址+端口
	'agent' => [
		'host' => '127.0.0.1',
		'port' => 7777,
	],
	//指定目标app地址+端口
	'apps' => [
		'host' => '127.0.0.1',
		'port' => 6379,
	],
];

if ($sproxy_conf['daemon']) {
	$pid = pcntl_fork();
	if ($pid != 0) {
		exit(0);
	}
}

for ($i = 2; $i < $sproxy_conf['process_num']; $i++) {
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
			$sproxy_conf['agent']['host'],
			$sproxy_conf['agent']['port'],
			$sproxy_conf['apps']['host'],
			$sproxy_conf['apps']['port']
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
