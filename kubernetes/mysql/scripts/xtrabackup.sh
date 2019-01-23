set -ex

echo "Waiting for mysqld to be ready (accepting connections)"
until mysql -h 127.0.0.1 -e "SELECT 1"; do sleep 1; done

# Create replication user
cd /mnt/scripts
if [[ -f create-replication-user.sql  ]]; then
cp create-replication-user.sql create-replication-user.orig.sql
cat create-replication-user.sql \
| sed s/@@REPLICATION_USER@@/"${MYSQL_REPLICATION_USER}"/g \
| sed s/@@REPLICATION_PASSWORD@@/"${MYSQL_REPLICATION_PASSWORD}"/g \
| tee create-replication-user.sql
mysql -h 127.0.0.1 --verbose < create-replication-user.sql
fi

cd /var/lib/mysql
# Determine binlog position of cloned data, if any.
if [[ -f xtrabackup_slave_info ]]; then
# XtraBackup already generated a partial "CHANGE MASTER TO" query
# because we're cloning from an existing slave.
cp xtrabackup_slave_info change_master_to.sql.in
elif [[ -f xtrabackup_binlog_info ]]; then
# We're cloning directly from master. Parse binlog position.
[[ $(cat xtrabackup_binlog_info) =~ ^(.*?)[[:space:]]+(.*?)$ ]] || exit 1
echo "CHANGE MASTER TO MASTER_LOG_FILE='${BASH_REMATCH[1]}',\
  MASTER_LOG_POS=${BASH_REMATCH[2]}" > change_master_to.sql.in
fi

# Check if we need to complete a clone by starting replication.
if [[ -f change_master_to.sql.in ]]; then

# In case of container restart, attempt this at-most-once.
cp change_master_to.sql.in change_master_to.sql.orig
mysql -h 127.0.0.1 --verbose<<EOF
STOP SLAVE IO_THREAD;
$(<change_master_to.sql.orig),
MASTER_HOST='${MYSQL_NAME}-0.${MYSQL_NAME}',
MASTER_USER='${MYSQL_REPLICATION_USER}',
MASTER_PASSWORD='${MYSQL_REPLICATION_PASSWORD}',
MASTER_CONNECT_RETRY=10;
START SLAVE;
EOF
fi

# Start a server to send backups when requested by peers.
exec ncat --listen --keep-open --send-only --max-conns=1 3307 -c \
"xtrabackup --backup --slave-info --stream=xbstream --host=127.0.0.1 --user=root --password=${MYSQL_ROOT_PASSWORD}"