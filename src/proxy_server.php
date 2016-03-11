<?php

define('ROOT', __DIR__);

include ROOT . '/conf.php';

class App {

	static $machine_clients = [];
	static $player_clients  = [];

	public static function set_cli_process_title($title) {
		if (PHP_OS != 'Darwin') {
			 @swoole_set_process_name($title);
  		}
	}
}

$setting = [
	'daemonize'          => $proxy_conf['daemonize'],
	'backlog'            => 128,
	'reactor_num'        => swoole_cpu_num(),     //默认会启用CPU核数相同的数量
	'worker_num'         => 4 * swoole_cpu_num(), //设置为CPU的1-4倍最合理
	'dispatch_mode'      => 2, //2 固定模式，根据连接的文件描述符分配worker。这样可以保证同一个连接发来的数据只会被同一个worker处理
	'open_tcp_nodelay'   => 1,
	'enable_reuse_port ' => 1,
	'log_file' 			 => ROOT . '/logs/proxy.log', //指定swoole错误日志文件
];

$proxy_server = new swoole_server($proxy_conf['public_ip'], $proxy_conf['public_port']);
$proxy_client = $proxy_server->listen($proxy_conf['public_ip'], $proxy_conf['public_proxy_port'], SWOOLE_SOCK_TCP);

$proxy_server->set($setting);

$process_handle = new swoole_process(function(swoole_process $process_handle) use ($argv, $proxy_server) {
	echo "process_start|" . $process_handle->pid . PHP_EOL;
	App::set_cli_process_title("php {$argv[0]} proxy_server_process");

	while(1) {
		try {
			$task_data   = $process_handle->read();
			$task_detail = json_decode($task_data, 1);

			echo "task_detail|" . $task_data . PHP_EOL;

			switch ($task_detail['from']) {
				case 'machine':
					$machine_fd = $task_detail['fd'];

					if ('connect' == $task_detail['event']) {
						App::$machine_clients[$machine_fd] = [
							'status' 	=> 'idle',
							'player_fd' => null,
						];
					}

					if ('close' == $task_detail['event']) {
						$player_fd = null;

						if (isset(App::$machine_clients[$machine_fd])) {
							$player_fd = App::$machine_clients[$machine_fd]['player_fd'];
							unset(App::$machine_clients[$machine_fd]);
						}

						if ($player_fd !== null) {
							unset(App::$player_clients[$machine_fd]);
							$proxy_server->close($player_fd);
						}
					}

					if ('receive' == $task_detail['event']) {
						$machine_data = base64_decode($task_detail['data']);
						$player_fd    = App::$machine_clients[$machine_fd]['player_fd'];

						if (isset(App::$player_clients[$player_fd])
							&& $proxy_server->exist($player_fd)) {
							$proxy_server->send($player_fd, $machine_data);
						} else {
							//close connect
							//swoole_process内使用swoole_server去close测试发现不会触发onClose
							{
								if (isset(App::$player_clients[$player_fd])) {
									unset(App::$player_clients[$machine_fd]);
									$proxy_server->close($player_fd);
								}

								if (isset(App::$machine_clients[$machine_fd])) {
									unset(App::$machine_clients[$machine_fd]);
									$proxy_server->close($machine_fd);
								}
							}
						}
					}
					break;
				case 'player':
					$player_fd = $task_detail['fd'];

					if ('connect' == $task_detail['event']) {
						$connected = false;
						foreach (App::$machine_clients as $machine_fd => $machine_info) {
							if ($machine_info['status'] == 'idle') {
								App::$player_clients[$player_fd] = [
									'machine_fd' =>	$machine_fd,
								];
								App::$machine_clients[$machine_fd]['status']    = 'work';
								App::$machine_clients[$machine_fd]['player_fd'] = $player_fd;
								$connected = true;
								break;
							}
						}

						if (!$connected) {
							echo "force_close|" . json_encode($task_detail['client']) . PHP_EOL;
							$proxy_server->close($player_fd);
						}
					}

					if ('close' == $task_detail['event']) {
						$machine_fd = null;

						if (isset(App::$player_clients[$player_fd])) {
							$machine_fd = App::$player_clients[$player_fd]['machine_fd'];
							unset(App::$player_clients[$player_fd]);
						}

						if ($machine_fd !== null) {
							unset(App::$machine_clients[$machine_fd]);
							$proxy_server->close($machine_fd);
						}
					}

					//避免出现force_close延迟是收到数据
					if (!isset(App::$player_clients[$player_fd])) {
						echo "reforce_close|" . json_encode($task_detail['client']) . PHP_EOL;
						$proxy_server->close($player_fd);
						$task_detail['event'] = null;
					}

					if ('receive' == $task_detail['event']) {
						$player_data = base64_decode($task_detail['data']);
						$machine_fd  = App::$player_clients[$player_fd]['machine_fd'];

						if (isset(App::$machine_clients[$machine_fd])
							&& $proxy_server->exist($machine_fd)) {
							$proxy_server->send($machine_fd, $player_data);
						} else {
							//close connect
							//swoole_process内使用swoole_server去close测试发现不会触发onClose
							{
								if (isset(App::$player_clients[$player_fd])) {
									unset(App::$player_clients[$machine_fd]);
									$proxy_server->close($player_fd);
								}

								if (isset(App::$machine_clients[$machine_fd])) {
									unset(App::$machine_clients[$machine_fd]);
									$proxy_server->close($machine_fd);
								}
							}
						}
					}
					break;
			}
		} catch (Throwable $e) {

		}
	}
	$process_handle->close();
	$process_handle->exit();
}, false, 2);

$proxy_server->addProcess($process_handle);


$proxy_server->on('start', function(swoole_server $proxy_server) use ($argv) {
	echo "master_start|" . getmypid() . PHP_EOL;
	App::set_cli_process_title("php {$argv[0]} proxy_server_master");
});

$proxy_server->on('shutDown', function(swoole_server $proxy_server) {});

$proxy_server->on('managerStart', function(swoole_server $proxy_server) use ($argv) {
	echo "manager_start|" . getmypid() . PHP_EOL;
	App::set_cli_process_title("php {$argv[0]} proxy_server_manager");
});

$proxy_server->on('managerStop', function(swoole_server $proxy_server) {});

$proxy_server->on('workerStart', function(swoole_server $proxy_server, $worker_id) use ($argv) {
	echo "worker_start|" . getmypid() . PHP_EOL;
	if ($proxy_server->taskworker) {
		App::set_cli_process_title("php {$argv[0]} proxy_server_task_worker");
	} else {
		App::set_cli_process_title("php {$argv[0]} proxy_server_event_worker");
	}
});

$proxy_server->on('workerStop', function(swoole_server $proxy_server, $worker_id) {});

$proxy_server->on('workerError', function(swoole_server $proxy_server, $worker_id, $worker_pid, $exit_code, $signo) {});

/**
 * close
 */
$proxy_server->on('close', function(swoole_server $proxy_server, $fd, $from_reactor_id) use ($process_handle) {
	echo 'player_close|' . getmypid() . '|' . json_encode($proxy_server->connection_info($fd)). PHP_EOL;

	$process_handle->write(json_encode([
		'from'   => 'player',
		'fd'     => $fd,
		'event'  => 'close',
		'client' => $proxy_server->connection_info($fd),
   	]));
});

$proxy_client->on('close', function(swoole_server $proxy_client, $fd, $from_reactor_id) use ($process_handle) {
	echo 'machine_close|' . getmypid() . '|' . json_encode($proxy_client->connection_info($fd)). PHP_EOL;

	$process_handle->write(json_encode([
		'from'   => 'machine',
		'fd'     => $fd,
		'event'  => 'close',
		'client' => $proxy_client->connection_info($fd),
   	]));
});

/**
 * connect
 */
$proxy_server->on('connect', function(swoole_server $proxy_server, $fd, $from_reactor_id) use ($process_handle) {
	echo 'player_connect|' . getmypid() . '|' . json_encode($proxy_server->connection_info($fd)). PHP_EOL;

	$process_handle->write(json_encode([
		'from'   => 'player',
		'fd'     => $fd,
		'event'  => 'connect',
		'client' => $proxy_server->connection_info($fd),
	]));
});

$proxy_client->on('connect', function(swoole_server $proxy_client, $fd, $from_reactor_id) use ($process_handle) {
	echo 'machine_connect|' . getmypid() . '|' . json_encode($proxy_client->connection_info($fd)). PHP_EOL;

	$process_handle->write(json_encode([
		'from'   => 'machine',
		'fd'     => $fd,
		'event'  => 'connect',
		'client' => $proxy_client->connection_info($fd),
	]));
});

$proxy_server->on('receive', function(swoole_server $proxy_server, $fd, $from_reactor_id, $data) use ($process_handle) {
	$process_handle->write(json_encode([
		'from'   => 'player',
		'fd'     => $fd,
		'event'  => 'receive',
		'data'   => base64_encode($data),
		'client' => $proxy_server->connection_info($fd),
	]));
});

$proxy_client->on('receive', function(swoole_server $proxy_client, $fd, $from_reactor_id, $data) use ($process_handle) {
	$process_handle->write(json_encode([
		'from'   => 'machine',
		'fd'     => $fd,
		'event'  => 'receive',
		'data'   => base64_encode($data),
		'client' => $proxy_client->connection_info($fd),
	]));
});

$proxy_server->start();

