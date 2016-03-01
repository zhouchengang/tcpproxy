# tcpproxy


假设我们希望有一台机器A(ip 192.168.1.101)要开放给用户端口6379给用户访问，但可能实际情况是用户无法能直接访问到A(ip 192.168.1.101), 但却有一台机器B(ip 192.168.1.100) 可以开放给用户访问，为了让用户通过B(ip 192.168.1.100)能访问到A(ip 192.168.1.101)上6379端口，基于swoole实现的Tcpproxy解决了类似该问题！ 当然你可以联想到我们家里的内部机器是在外网无法访问的，可正好你有一台云服务器，所以我们可以通过Tcpproxy实现外部访问你家里的内网应用. 按照上面描述的情况，配置我们的服务选项后如下


```php
//开放给用户的公网的ip
$proxy_conf['public_ip'] = '192.168.1.100';
//开放给用户的公网的端口
$proxy_conf['public_port'] = 9999;

//代理内部中转端口
$proxy_conf['public_proxy_port'] = 6677;

//内部无法开放给公网的ip
$proxy_conf['local_ip'] = '192.168.1.101';
//内部无法开放给公网的端口
$proxy_conf['local_port'] = 6379;

//开放连接数
$proxy_conf['open_num'] = 10;

//守护进程模式
$proxy_conf['daemonize'] = 0;
```

在B服务器运行
```
php proxy_server.php
```

在A服务器运行
```
php proxy_client.php
```

试试吧
