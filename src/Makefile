all:sproxy
CC=gcc
CFLAGS=-O2 -Wall -pthread

sproxy: sproxy.c
	$(CC) -o sproxy sproxy.c $(CFLAGS)
	
sproxy_thread: sproxy_thread.c
	$(CC) -o sproxy_thread sproxy_thread.c $(CFLAGS)	

clean:
	rm -rf sproxy_thread sproxy a.out