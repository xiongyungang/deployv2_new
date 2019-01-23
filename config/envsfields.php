<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/1/18
 * Time: 16:56
 */

/**
 * fields which can be envs
 */
return [
    'mysql' => [
        'name' => 'MYSQL',
        'fields' => [
            'HOST_WRITE' => 'host_write',
            'HOST_READ' => 'host_read',
            'PORT' => 'port',
            'USERNAME' => 'username',
            'PASSWORD' => 'password',
        ],
    ],
    'mysql_database' => [
        'name' => 'MYSQL_DATABASE',
        'fields' => [
            'HOST_WRITE' => 'mysql->host_write',
            'HOST_READ' => 'mysql->host_read',
            'PORT' => 'mysql->port',
            'NAME' => 'database_name',
            'USERNAME' => 'username',
            'PASSWORD' => 'password',
        ],
    ],
    'mongodb' => [
        'name' => 'MONGODB',
        'fields' => [
            'HOST_WRITE' => 'host_write',
            'HOST_READ' => 'host_read',
            'PORT' => 'port',
            'USERNAME' => 'username',
            'PASSWORD' => 'password',
        ],
    ],
    'mongodb_database' => [
        'name' => 'MONGODB_DATABASE',
        'fields' => [
            'HOST_WRITE' => 'mongodb->host_write',
            'HOST_READ' => 'mongodb->host_read',
            'PORT' => 'mongodb->port',
            'NAME' => 'database_name',
            'USERNAME' => 'username',
            'PASSWORD' => 'password',
        ],
    ],
    'redis' => [
        'name' => 'REDIS',
        'fields' => [
            'HOST_WRITE' => 'host_write',
            'HOST_READ' => 'host_read',
            'PORT' => 'port',
            'PASSWORD' => 'password',
        ],
    ],
    'memcached' => [
        'name' => 'MEMCACHED',
        'fields' => [
            'HOST' => 'host',
            'PORT' => 'port',
            'USERNAME' => 'username',
            'PASSWORD' => 'password',
        ],
    ],
    'rabbitmq' => [
        'name' => 'RABBITMQ',
        'fields' => [
            'HOST' => 'host',
            'PORT' => 'port',
            'USERNAME' => 'username',
            'PASSWORD' => 'password',
        ],
    ],
    'deployment' => [
        'name' => 'DEPLOYMENT',
        'fields' => [
            'HOST' => 'host',
            'DOMAIN' => 'domain',
        ],
    ],
    'workspace' => [
        'name' => 'WORKSPACE',
        'fields' => [
            'HOST' => 'host',
            'HOSTNAME' => 'hostname',
            'SSH_PRIVATE_KEY' => 'ssh_private_key',
        ],
    ],
];