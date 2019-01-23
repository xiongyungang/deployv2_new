set -ex
# Skip the clone on master (ordinal index 0).
[[ `hostname` =~ -([0-9]+)$ ]] || exit 1
ordinal=${BASH_REMATCH[1]}
[[ $ordinal -eq 0 ]] && exit 0

# If data already exists, delete and proceed to clone.
[[ -d /var/lib/mysql/mysql ]] && rm -fr /var/lib/mysql/*

# Clone data from previous peer.
ncat --recv-only ${MYSQL_NAME}-$(($ordinal-1)).${MYSQL_NAME} 3307 | xbstream -x -C /var/lib/mysql
# Prepare the backup.
xtrabackup --prepare --user=root --password=${MYSQL_ROOT_PASSWORD} --target-dir=/var/lib/mysql