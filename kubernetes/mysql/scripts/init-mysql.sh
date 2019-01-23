set -ex
# Generate mysql server-id from pod ordinal index.
[[ `hostname` =~ -([0-9]+)$ ]] || exit 1
ordinal=${BASH_REMATCH[1]}
# Copy server-id.conf adding offset to avoid reserved server-id=0 value.
cat /mnt/config-map/server-id.cnf | sed s/@@SERVER_ID@@/$((100 + $ordinal))/g > /mnt/conf.d/server-id.cnf
# Copy appropriate conf.d files from config-map to config mount.
if [[ $ordinal -eq 0 ]]; then
  cp /mnt/config-map/master.cnf /mnt/conf.d/
else
  cp /mnt/config-map/slave.cnf /mnt/conf.d/
fi
# Copy replication user script
if [[ $ordinal -eq 0 ]]; then
  cp /mnt/config-map/create-replication-user.sql /mnt/scripts/create-replication-user.sql
fi