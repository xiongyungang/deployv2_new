FROM registry.cn-hangzhou.aliyuncs.com/deployv2/lnmp:php-7.1

ENV QUEUE_WORKER_NUM 1

COPY ./docker/lnmp/queue_worker_init.sh /etc/my_init.d/

COPY ./product_init.sh /etc/my_init.d/

COPY ./docker/lnmp/schedule.cron /etc/cron.d/schedule

RUN chmod +x /etc/my_init.d/product_init.sh && \
chmod +x /etc/my_init.d/queue_worker_init.sh && \
chmod 600 /etc/cron.d/schedule


COPY ./ /opt/ci123/www/html
