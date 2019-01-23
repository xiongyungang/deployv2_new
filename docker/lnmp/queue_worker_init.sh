#!/bin/bash

set -xe

COUNTER=0
while [ $COUNTER -lt $QUEUE_WORKER_NUM ]; do
    service_script_path=/etc/service/queue_$COUNTER
    mkdir -p $service_script_path
    echo -e '#!/bin/bash \n php /opt/ci123/www/html/artisan queue:work' > $service_script_path/run
    chmod +x $service_script_path/run
    let COUNTER=COUNTER+1
done