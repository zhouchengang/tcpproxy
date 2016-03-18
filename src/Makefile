all:sproxy_process sproxy_thread
CC=gcc
CFLAGS=-O2 -Wall -pthread -DSPROXY_THREAD_NUM=20

sproxy_process: sproxy_process.c
	$(CC) -o sproxy_process sproxy_process.c $(CFLAGS)
	
sproxy_thread: sproxy_thread.c
	$(CC) -o sproxy_thread sproxy_thread.c $(CFLAGS)

clean:
	rm -rf sproxy_thread sproxy_process a.out
