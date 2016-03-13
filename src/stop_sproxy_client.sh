#!/usr/bin/env bash

PROCESS_NUM=`ps axu | grep "sproxy_client" | grep -v "grep" | wc -l`
if [ ${PROCESS_NUM} -ne 0 ]; then
    ps axu | grep "sproxy_client" | grep -v "grep" | awk -F " " '{print $2}' | xargs kill -9
fi

PROCESS_NUM=`ps axu | grep "sproxy_process" | grep -v "grep" | wc -l`
if [ ${PROCESS_NUM} -ne 0 ]; then
    ps axu | grep "sproxy_process" | grep -v "grep" | awk -F " " '{print $2}' | xargs kill -9
fi

PROCESS_NUM=`ps axu | grep "sproxy_thread" | grep -v "grep" | wc -l`
if [ ${PROCESS_NUM} -ne 0 ]; then
    ps axu | grep "sproxy_thread" | grep -v "grep" | awk -F " " '{print $2}' | xargs kill -9
fi