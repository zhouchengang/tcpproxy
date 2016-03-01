<?php
class ProxyTarget
{

	private $_proxy_handle;
	private $_target_client_handle;

	private $_target_server_ip;
	private $_target_server_port;

	private $_target_connected = false;

	private $_pid;

	public function __construct($proxy_handle, $target_server_ip, $target_server_port) {
		$this->_proxy_handle = $proxy_handle;
		$this->_target_server_ip    = $target_server_ip;
		$this->_target_server_port  = $target_server_port;

		$this->_pid = getmypid();

		$this->_target_client_handle = new swoole_client(SWOOLE_TCP, SWOOLE_SOCK_ASYNC);
		$this->_target_client_handle->on('error', array($this, 'error'));
		$this->_target_client_handle->on('close', array($this, 'close'));
		$this->_target_client_handle->on('connect', array($this, 'connect'));
		$this->_target_client_handle->on('receive', array($this, 'receive'));
	}

	public function run($connect_callback = null) {
		$this->_target_client_handle->ccb = $connect_callback;
		$this->_target_client_handle->connect($this->_target_server_ip, $this->_target_server_port);
	}

	public function error(swoole_client $target_server_handle) {
		echo "target_error|" . $this->_pid . PHP_EOL;

		if ($this->_target_connected) {
			$target_server_handle->close();
		}

		$this->close_proxy();
	}

	public function close(swoole_client $target_server_handle) {
		echo "target_close|" . $this->_pid . PHP_EOL;

		$this->_target_connected = false;

		$this->close_proxy();
	}

	public function connect(swoole_client $target_server_handle) {
		echo "target_connect|" . $this->_pid  . "|" . $this->_target_server_ip . ':' . $this->_target_server_port. PHP_EOL;

		$this->_target_connected = true;

		if (isset($this->_target_client_handle->ccb)) {
			call_user_func_array($this->_target_client_handle->ccb, array(
				$this->_pid,
				$this->_target_client_handle,
				$this->_proxy_handle ? $this->_proxy_handle : null
			));
		}
	}

	public function receive(swoole_client $target_server_handle, $data) {
		echo "target_receive|" . $this->_pid . "|" . base64_encode($data) .PHP_EOL;

		if ($this->_proxy_handle) {
			$this->_proxy_handle->send_proxy($data);
		}
	}

	public function close_proxy() {
		if ($this->_proxy_handle) {
			$this->_proxy_handle->close_proxy();
			$this->_proxy_handle = null;
		}
	}

	public function close_target() {
		if ($this->_target_connected) {
			$this->_target_client_handle->close();
		}
	}

	public function send_target($data) {
		$this->_target_client_handle->send($data);
	}
}


class ProxyClient
{

	public $proxy_server_ip;
	public $proxy_server_port;

	public $target_server_ip;
	public $target_server_port;

	private $_proxy_connected = false;

	private $_pid;

	private $_proxy_client_handle = null;

	private $_target_handle = null;

	private $_msg_queue_handle = null;

	public function __construct($proxy_server_ip, $proxy_server_port, $target_server_ip, $target_server_port) {
		$this->_pid = getmypid();

		$this->proxy_server_ip    = $proxy_server_ip;
		$this->proxy_server_port  = $proxy_server_port;
		$this->target_server_ip   = $target_server_ip;
		$this->target_server_port = $target_server_port;
	}

	public function run() {
		$this->_proxy_client_handle = new swoole_client(SWOOLE_TCP, SWOOLE_SOCK_ASYNC);
		$this->_proxy_client_handle->on('error', array($this, 'error'));
		$this->_proxy_client_handle->on('close', array($this, 'close'));
		$this->_proxy_client_handle->on('connect', array($this, 'connect'));
		$this->_proxy_client_handle->on('receive', array($this, 'receive'));
		$this->_proxy_client_handle->connect($this->proxy_server_ip, $this->proxy_server_port);
	}

	public function error(swoole_client $proxy_client_handle) {
		echo "proxy_error|" . $this->_pid . PHP_EOL;

		if ($this->_proxy_connected) {
			$proxy_client_handle->close();
		}
	}

	public function close(swoole_client $proxy_client_handle) {
		echo "proxy_close|" . $this->_pid . PHP_EOL;

		$this->_proxy_connected = false;

		$this->close_target();

		if ($this->_msg_queue_handle
			&& msg_queue_exists($this->_pid)) {
			msg_remove_queue($this->_msg_queue_handle);
			$this->_msg_queue_handle = null;
		}
	}

	public function connect(swoole_client $proxy_client_handle) {
		echo "proxy_connect|" . $this->_pid . PHP_EOL;

		$this->_proxy_connected = true;
	}

	public function receive(swoole_client $proxy_client_handle, $data) {
		if (is_null($this->_target_handle)) {
			echo "proxy_target|" . $this->_pid . PHP_EOL;
			$this->_target_handle = new ProxyTarget($this, $this->target_server_ip, $this->target_server_port);
			$this->_target_handle->run(array($this, 'target_connect_callback'));

			$this->_msg_queue_handle = msg_get_queue($this->_pid);
			//clear
			msg_remove_queue($this->_msg_queue_handle);
			//use again
			$this->_msg_queue_handle = msg_get_queue($this->_pid);
		}

		echo "proxy_receive|" . $this->_pid . "|" . base64_encode($data) . PHP_EOL;

		msg_send($this->_msg_queue_handle, 1, $data, false, false);
	}

	public function get_msg_queue() {
		return $this->_msg_queue_handle;
	}

	public function send_proxy($data) {
		echo "proxy_send|" . $this->_pid . "|" . base64_encode($data) . PHP_EOL;

		$this->_proxy_client_handle->send($data);
	}

	public function close_proxy() {
		if ($this->_proxy_connected) {
			$this->_proxy_client_handle->close();
		}
	}

	public function close_target() {
		if ($this->_target_handle) {
			$this->_target_handle->close_target();
			$this->_target_handle = null;
		}
	}

	public function target_connect_callback($proxy_pid, $target_client_handle, $proxy_handle) {
		$pid = pcntl_fork();
		if (0 == $pid) {
			//fork child
			$pid = getmypid();
			echo "fork_child|" . getmypid() . PHP_EOL;
			while (1) {
				if (!msg_queue_exists($proxy_pid)) {
					echo "queue_exit|" . $pid . PHP_EOL;

					break;
				}

				if (msg_receive($proxy_handle->get_msg_queue(), 0, $message_type, 10240, $message, false)) {
					echo "queue_message|" . $pid . "|" . base64_encode($message) . PHP_EOL;
					$target_client_handle->send($message);
				}
			}

			swoole_event_exit();
		}
	}
}
