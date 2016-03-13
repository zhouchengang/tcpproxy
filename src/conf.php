<?php

//守护进程模式
$proxy_conf['daemon'] = 0;

//sproxy_server.php配置

//开放给用户的公网的ip
$proxy_conf['public_ip'] = '127.0.0.1';
//开放给用户的公网的端口
$proxy_conf['public_port'] = 9999;
//代理内部中转端口
$proxy_conf['public_proxy_port'] = 7777;

//sproxy_client.php 配置

//内部无法开放给公网的ip
$proxy_conf['local_ip'] = '127.0.0.1';
//内部无法开放给公网的端口
$proxy_conf['local_port'] = 6379;
//开放连接数
$proxy_conf['open_num'] = 10;

return $proxy_conf;

