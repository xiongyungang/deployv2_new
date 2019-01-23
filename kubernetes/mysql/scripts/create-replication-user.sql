CREATE USER IF NOT EXISTS '@@REPLICATION_USER@@' IDENTIFIED BY '@@REPLICATION_PASSWORD@@';
GRANT PROCESS, RELOAD, REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO '@@REPLICATION_USER@@';
FLUSH PRIVILEGES;