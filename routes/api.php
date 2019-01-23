<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('v1')->group(function () {
    Route::get('clusters', 'ClusterController@getAll');
    Route::post('clusters', 'ClusterController@create');
    Route::get('clusters/{cluster}', 'ClusterController@get');
    Route::put('clusters/{cluster}', 'ClusterController@update');
    Route::delete('clusters/{cluster}', 'ClusterController@destroy');

    Route::get('namespaces', 'NamespaceController@getAll');
    Route::post('namespaces', 'NamespaceController@create');
    Route::get('namespaces/{namespace}', 'NamespaceController@get');
    Route::put('namespaces/{namespace}', 'NamespaceController@update');
    Route::delete('namespaces/{namespace}', 'NamespaceController@destroy');

    Route::get('deployments', 'DeploymentController@getAll');
    Route::post('deployments', 'DeploymentController@create');
    Route::get('deployments/{deployment}', 'DeploymentController@get');
    Route::put('deployments/{deployment}', 'DeploymentController@update');
    Route::post('deployments/{deployment}/restart', 'DeploymentController@restart');
    Route::post('deployments/{deployment}/stop', 'DeploymentController@stop');
    Route::delete('deployments/{deployment}', 'DeploymentController@destroy');

    Route::get('workspaces', 'WorkspaceController@getAll');
    Route::post('workspaces', 'WorkspaceController@create');
    Route::get('workspaces/{workspace}', 'WorkspaceController@get');
    Route::put('workspaces/{workspace}', 'WorkspaceController@update');
    Route::post('workspaces/{workspace}/start', 'WorkspaceController@start');
    Route::post('workspaces/{workspace}/stop', 'WorkspaceController@stop');
    Route::post('workspaces/{workspace}/restart', 'WorkspaceController@restart');
    Route::delete('workspaces/{workspace}', 'WorkspaceController@destroy');
    
    Route::get('mysqls', 'MysqlController@getAll');
    Route::post('mysqls', 'MysqlController@create');
    Route::post('mysqls/{mysql}/restart', 'MysqlController@restart');
    Route::post('mysqls/{mysql}/start', 'MysqlController@restart');
    Route::post('mysqls/{mysql}/stop', 'MysqlController@stop');
    Route::get('mysqls/{mysql}', 'MysqlController@get');
    Route::put('mysqls/{mysql}', 'MysqlController@update');
    Route::delete('mysqls/{mysql}', 'MysqlController@destroy');
    
    Route::get('mysql_databases', 'MysqlDatabasesController@getAll');
    Route::post('mysql_databases', 'MysqlDatabasesController@create');
    Route::get('mysql_databases/{database}', 'MysqlDatabasesController@get');
    Route::put('mysql_databases/{database}', 'MysqlDatabasesController@update');
    Route::post('mysql_databases/{database}/restart', 'MysqlDatabasesController@restart');
    Route::delete('mysql_databases/{database}', 'MysqlDatabasesController@destroy');

    Route::post('modelconfigs', 'ModelconfigController@create');
    Route::put('modelconfigs/{modelconfig}', 'ModelconfigController@update');
    Route::get('modelconfigs', 'ModelconfigController@getAll');
    Route::get('modelconfigs/{modelconfig}', 'ModelconfigController@get');
    Route::delete('modelconfigs/{modelconfig}', 'ModelconfigController@destroy');

    Route::post('datamigrations', 'DataMigrationController@create');
    Route::put('datamigrations/{dataMigration}', 'DataMigrationController@update');
    Route::get('datamigrations', 'DataMigrationController@getAll');
    Route::get('datamigrations/{dataMigration}', 'DataMigrationController@get');
    Route::delete('datamigrations/{dataMigration}/destroy', 'DataMigrationController@destroy');
    
    Route::get('redises', 'RedisController@getAll');
    Route::post('redises', 'RedisController@create');
    Route::get('redises/{redis}', 'RedisController@get');
    Route::put('redises/{redis}', 'RedisController@update');
    Route::post('redises/{redis}/restart', 'RedisController@restart');
    Route::post('redises/{redis}/start', 'RedisController@start');
    Route::post('redises/{redis}/stop', 'RedisController@stop');
    Route::delete('redises/{redis}', 'RedisController@destroy');
    
    Route::get('rabbitmqs', 'RabbitmqController@getAll');
    Route::post('rabbitmqs', 'RabbitmqController@create');
    Route::get('rabbitmqs/{rabbitmq}', 'RabbitmqController@get');
    Route::put('rabbitmqs/{rabbitmq}', 'RabbitmqController@update');
    Route::post('rabbitmqs/{rabbitmq}/restart', 'RabbitmqController@restart');
    Route::post('rabbitmqs/{rabbitmq}/start', 'RabbitmqController@restart');
    Route::post('rabbitmqs/{rabbitmq}/stop', 'RabbitmqController@stop');
    Route::delete('rabbitmqs/{rabbitmq}', 'RabbitmqController@destroy');
    
    Route::get('mongodbs', 'MongodbController@getAll');
    Route::post('mongodbs', 'MongodbController@create');
    Route::get('mongodbs/{mongodb}', 'MongodbController@get');
    Route::put('mongodbs/{mongodb}', 'MongodbController@update');
    Route::post('mongodbs/{mongodb}/restart', 'MongodbController@restart');
    Route::post('mongodbs/{mongodb}/start', 'MongodbController@restart');
    Route::post('mongodbs/{mongodb}/stop', 'MongodbController@stop');
    Route::delete('mongodbs/{mongodb}', 'MongodbController@destroy');
    
    Route::get('mongodb_databases', 'MongodbDatabasesController@getAll');
    Route::post('mongodb_databases', 'MongodbDatabasesController@create');
    Route::get('mongodb_databases/{database}', 'MongodbDatabasesController@get');
    Route::put('mongodb_databases/{database}', 'MongodbDatabasesController@update');
    Route::post('mongodb_databases/{database}/restart', 'MongodbDatabasesController@restart');
    Route::delete('mongodb_databases/{database}', 'MongodbDatabasesController@destroy');
    
    Route::get('memcacheds', 'MemcachedController@getAll');
    Route::post('memcacheds', 'MemcachedController@create');
    Route::get('memcacheds/{memcached}', 'MemcachedController@get');
    Route::put('memcacheds/{memcached}', 'MemcachedController@update');
    Route::post('memcacheds/{memcached}/restart', 'MemcachedController@restart');
    Route::post('memcacheds/{memcached}/start', 'MemcachedController@start');
    Route::post('memcacheds/{memcached}/stop', 'MemcachedController@stop');
    Route::delete('memcacheds/{memcached}', 'MemcachedController@destroy');

    Route::get('tasks', 'TaskController@getAll');
    //创建
    Route::post('tasks', 'TaskController@create');
    //停止
    Route::post('tasks/{task}/stop', 'TaskController@stop');
    Route::get('tasks/{task}', 'TaskController@get');
    //接受下层回调
    Route::post('tasks/receiver', 'TaskController@receiver');
});
