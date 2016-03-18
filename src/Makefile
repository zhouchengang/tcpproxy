all:sproxy_process sproxy_thread
CC=gcc
CFLAGS=-O2 -Wall -pthread 
CPPFLAGS=-DSPROXY_THREAD_NUM=20

sproxy_process: sproxy_process.c
	$(CC) -o sproxy_process sproxy_process.c $(CFLAGS) $(CPPFLAGS)
	
sproxy_thread: sproxy_thread.c
	$(CC) -o sproxy_thread sproxy_thread.c $(CFLAGS) $(CPPFLAGS)

clean:
	rm -rf sproxy_thread sproxy_process a.out
