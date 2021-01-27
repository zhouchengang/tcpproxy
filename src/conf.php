<?php

//守护进程模式
$proxy_conf['daemon'] = 1;
//开放地址
$proxy_conf['host'] = '47.240.168.158';
//开放端口
$proxy_conf['ports'] = [
	[9999, 7777, 0],
	[9998, 6666, 1]
];

return $proxy_conf;

