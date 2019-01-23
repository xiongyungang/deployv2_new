<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/1/18
 * Time: 13:52
 */

return [
    //
    'type_to_model_name' => [
        'cluster' => 'App\Cluster',
        'namespace' => 'App\K8sNamespace',
        'deployment' => 'App\Deployment',
        'workspace' => 'App\Workspace',
        'model_config' => 'App\Modelconfig',
        'data_migration' => 'App\DataMigration',
        'mysql' => 'App\Mysql',
        'mysql_database' => 'App\MysqlDatabase',
        'mongodb' => 'App\Mongodb',
        'mongodb_database' => 'App\MongodbDatabase',
        'redis' => 'App\Redis',
        'memcached' => 'App\Memcached',
        'rabbitmq' => 'App\Rabbitmq',
    ],
    'model_name_to_type' => [
        'App\Cluster' => 'cluster',
        'App\K8sNamespace' => 'namespace',
        'App\Deployment' => 'deployment',
        'App\Workspace' => 'workspace',
        'App\Modelconfig' => 'model_config',
        'App\DataMigration' => 'data_migration',
        'App\Mysql' => 'mysql',
        'App\MysqlDatabase' => 'mysql_database',
        'App\Mongodb' => 'mongodb',
        'App\MongodbDatabase' => 'mongodb_database',
        'App\Redis' => 'redis',
        'App\Memcached' => 'memcached',
        'App\Rabbitmq' => 'rabbitmq',
    ],
    'type_to_controller_name' => [
        'cluster' => 'App\Http\Controllers\ClusterController',
        'namespace' => 'App\Http\Controllers\NamespaceController',
        'deployment' => 'App\Http\Controllers\DeploymentController',
        'workspace' => 'App\Http\Controllers\WorkspaceController',
        'model_config' => 'App\Http\Controllers\ModelconfigController',
        'data_migration' => 'App\Http\Controllers\DataMigrationController',
        'mysql' => 'App\Http\Controllers\MysqlController',
        'mysql_database' => 'App\Http\Controllers\MysqlDatabasesController',
        'mongodb' => 'App\Http\Controllers\MongodbController',
        'mongodb_database' => 'App\Http\Controllers\MongodbDatabasesController',
        'redis' => 'App\Http\Controllers\RedisController',
        'memcached' => 'App\Http\Controllers\MemcachedController',
        'rabbitmq' => 'App\Http\Controllers\RabbitmqController',
    ],
];