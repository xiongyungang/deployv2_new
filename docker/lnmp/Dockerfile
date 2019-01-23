FROM registry.cn-shanghai.aliyuncs.com/itfarm/lnmp:php-7.1

ENV QUEUE_WORKER_NUM 1
COPY queue_worker_init.sh /etc/my_init.d/

COPY schedule.cron /etc/cron.d/schedule
