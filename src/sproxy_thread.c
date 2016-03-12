/**
 *
 * 星期八 QQ 174171262
 * http://xingqiba.sinaapp.com
 *
 * 代理转发
 *
 * Usage
 *
 * make
 * ./sproxy -s 127.0.0.1:6677 -t 127.0.0.1:6379 -c 10
 *
 * https://github.com/jonnywang/tcpproxy/blob/master/README.md
 *
 */

#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <sys/types.h>
#include <errno.h>
#include <string.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <stdarg.h>
#include <time.h>
#include <locale.h>
#include <sys/time.h>
#include <sys/select.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <pthread.h>

#ifdef TRUE
#undef TRUE
#endif

#ifdef FALSE
#undef FALSE
#endif

typedef enum { FALSE, TRUE } Boolean;

#define min(m,n) ((m) < (n) ? (m) : (n))
#define max(m,n) ((m) > (n) ? (m) : (n))
#define SLOG(fmt, ...) message(__FILE__, __LINE__, fmt, ##__VA_ARGS__)

void message(const char *filename, int line, const char *fmt, ...) {
	char sbuf[1024], tbuf[30];
	va_list args;
	time_t now;
	uint len;

	va_start(args, fmt);
	len = vsnprintf(sbuf, sizeof(sbuf), fmt, args);
	va_end(args);

	if (len >= sizeof(sbuf)) {
		memcpy(sbuf + sizeof(sbuf) - sizeof("..."), "...", sizeof("...") - 1);
		len = sizeof(sbuf) - 1;
	}
	sbuf[len] = '\0';

	now = time(NULL);
	strftime(tbuf, sizeof(tbuf), "%Y-%m-%d %X", localtime(&now));
	fprintf(stdout, "%s|%u|%s,%d|%s\n", tbuf, getpid(), filename, line, sbuf);
}

typedef struct {
	char proxy_server[16];
	char target_server[16];
	int proxy_port;
	int target_port;
} ProxyServer;

void show_usage(const char *name) {
	fprintf(stderr, "Usage like %s -s 127.0.0.1:6677 -t 127.0.0.1:6379 -c 10\n", name);
}

int connect_server(const char *host, int port) {
	struct sockaddr_in serv_addr;
	int	cfd = socket(AF_INET, SOCK_STREAM, 0);
	if (cfd == -1) {
		SLOG("connect server %s:%d failed", host, port);
		return cfd;
	}

	memset(&serv_addr, '0', sizeof(serv_addr));

	serv_addr.sin_family = AF_INET;
	serv_addr.sin_port   = htons(port);

	if (inet_pton(AF_INET, host, &serv_addr.sin_addr) <= 0){
		SLOG("connect server %s:%d failed", host, port);
		return cfd;
	}

	if (connect(cfd, (struct sockaddr *)&serv_addr, sizeof(serv_addr)) == -1) {
		SLOG("connect server %s:%d failed", host, port);
		return cfd;
	}

	if (fcntl(cfd, F_SETFL, fcntl(cfd, F_GETFL, 0) | O_NONBLOCK) == -1) {
		SLOG("set connect server %s:%d nonblocking failed", host, port);
		close(cfd);
		return -1;
	}

	SLOG("connect server %s:%d success", host, port);

	return cfd;
}

void *child_thread(void * arg) {
	SLOG("thread %ld start", (long)pthread_self());

	ProxyServer *ps = (ProxyServer *)((void *)arg);

	int cfd = -1,
		tfd = -1,
		nfds = -1,
		ready = -1,
		n = 0,
		m = 0;

	cfd = connect_server(ps->proxy_server, ps->proxy_port);
	if (cfd < 0) {
		goto endprocess;
	}

	fd_set readfds, exceptfds;
	char buf[1024];

	while (TRUE) {
		FD_ZERO(&readfds);
		FD_ZERO(&exceptfds);

		FD_SET(cfd, &readfds);
		FD_SET(cfd, &exceptfds);

		nfds = max(0, cfd);
		if (tfd != -1) {
			nfds = max(nfds, tfd);
			FD_SET(tfd, &readfds);
			FD_SET(tfd, &exceptfds);
		}

		ready = select(nfds + 1, &readfds, NULL, &exceptfds, NULL);
		if (ready < 0 && errno == EINTR) {

			continue;
		}

		if (ready <= 0) {
			SLOG("select read event failed");
			break;
		}

		if (FD_ISSET(cfd, &exceptfds)) {
			goto endprocess;
		}

		if (FD_ISSET(cfd, &readfds)) {
			SLOG("trigger proxy read");
			if (tfd == -1) {
				SLOG("trigger target connect");
				tfd = connect_server(ps->target_server, ps->target_port);
				if (tfd < 0) {
					goto endprocess;
				}
			}

			while ((n = read(cfd, buf, sizeof(buf))) > 0) {
				m = 0;
				while ((m = write(tfd, buf + m, n - m)) > 0) {}
			}

			if (n == 0) {
				goto endprocess;
			}
		}

		if (FD_ISSET(tfd, &readfds)) {
			while ((n = read(tfd, buf, sizeof(buf))) > 0) {
				m = 0;
				while ((m = write(cfd, buf + m, n - m)) > 0) {}
			}

			if (n == 0) {
				goto endprocess;
			}
		}
	}

	endprocess : {
		if (cfd > 0) {
			close(cfd);
		}

		if (tfd > 0) {
			close(tfd);
		}
	}

	SLOG("thread %ld disconnect server %s:%d success", (long)pthread_self(), ps->proxy_server, ps->proxy_port);
	SLOG("thread %ld end", (long)pthread_self());

	return (void *)0;
}

void child_process(ProxyServer ps, pid_t pre_pid, int index) {
	SLOG("forked child start|%d,%u,%u", index, pre_pid, getpid());

	int pt_i = 0;
	void *pt_r = NULL;
	pthread_t pt[50];
	int pt_num = sizeof(pt) / sizeof (pt[0]);

	for (pt_i = 0; pt_i < pt_num; pt_i++) {
		if (pthread_create(&pt[pt_i], NULL, child_thread, &ps) != 0) {
			SLOG("pthread_create failed");
		}
	}

	for (pt_i = 0; pt_i < pt_num; pt_i++) {
		if (pthread_join(pt[pt_i], pt_r) != 0) {
			SLOG("pthread_join failed");
		}
	}

	SLOG("child_process end");
}


int main(int argc, char *argv[]) {
	setlocale(LC_ALL, "");

	if (argc != 7
		|| strcmp(argv[1], "-s") != 0
		|| strstr(argv[2], ":") == NULL
		|| strcmp(argv[3], "-t") != 0
		|| strstr(argv[4], ":") == NULL
		|| strcmp(argv[5], "-c") != 0) {
		show_usage((const char *)argv[0]);
		return 1;
	}

	ProxyServer ps;
	if (sscanf(argv[2], "%[0-9.]:%d", ps.proxy_server, &ps.proxy_port) != 2
		|| sscanf(argv[4], "%[0-9.]:%d", ps.target_server, &ps.target_port) != 2) {
		show_usage((const char *)argv[0]);
		return 1;
	}

	int forked_child_num = 1;
	if (sscanf(argv[6], "%d", &forked_child_num) != 1
		|| forked_child_num < 1) {
		show_usage((const char *)argv[0]);
		return 1;
	}

	SLOG("sproxy want to connect %s:%d for %s:%d with %d",
			ps.proxy_server, ps.proxy_port,
			ps.target_server, ps.target_port,
			forked_child_num);

	int wait_child_status = 0,
		forked_child_now = 1;

	pid_t forked_pid = -1, wait_child_pid = 0;

	for (forked_child_now = 1; forked_child_now < forked_child_num; forked_child_now++) {
		forked_pid = fork();
		if (forked_pid == 0) {
			break;
		}
	}

	//子进程
	if (forked_pid == 0) {
		child_process(ps, wait_child_pid, forked_child_now);
		exit(0);
	}

	//父进程
	while (TRUE) {
		forked_pid = fork();
		if (forked_pid == 0) {
			//child
			child_process(ps, wait_child_pid, forked_child_now);
			exit(0);
		}

		wait_child_pid = wait(&wait_child_status);
		if (wait_child_status == -1) {
			break;
		}
		sleep(3);
		forked_child_now++;
		SLOG("child_exit|%u", wait_child_pid);
	}

	SLOG("parent_exit|%u", getpid());

	return 0;
}
