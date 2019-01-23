<?php

namespace App\Common;

use App\Cluster;
use App\DataMigration;
use App\Deployment;
use App\Http\Controllers\ClusterController;
use App\Http\Controllers\DataMigrationController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\MemcachedController;
use App\Http\Controllers\ModelConfigController;
use App\Http\Controllers\MongodbController;
use App\Http\Controllers\MongodbDatabasesController;
use App\Http\Controllers\MysqlController;
use App\Http\Controllers\MysqlDatabasesController;
use App\Http\Controllers\NamespaceController;
use App\Http\Controllers\RabbitmqController;
use App\Http\Controllers\RedisController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WorkspaceController;
use App\K8sNamespace;
use App\Memcached;
use App\ModelConfig;
use App\Mongodb;
use App\MongodbDatabase;
use App\Mysql;
use App\MysqlDatabase;
use App\Rabbitmq;
use App\Redis;
use App\RequestRecord;
use App\Task;
use App\Workspace;

class util
{
    
    /**
     * 当某个key只存在于oldArray时，向newArray添加值为null的key,最后返回newArray,
     * label发生更新，无法删除已存在k8s中旧label，需要手动赋值NULL才能删除
     *
     * @param array $oldArray
     * @param array $newArray
     * @return array $newArray
     */
    public function filterArray($oldArray, $newArray)
    {
        foreach ($oldArray as $k => $v) {
            if (!key_exists($k, $newArray)) {
                if (is_int($k)) {
                    $k = (string)$k;
                }
                $newArray[$k] = null;
            }
        }
        return $newArray;
    }
    
    /**
     * @param array $array1
     * @param array $array2
     * @return string
     */
    public function mergeArrayAndMd5($array1, $array2)
    {
        return md5(json_encode(array_merge($array1, $array2)));
    }

    /**
     * record request
     *
     * @param string $deployType
     * @param string $action
     * @param string $header
     * @param string $body
     */
    public function recordRequest($deployType, $action, $header, $body)
    {
        $requestRecord = [
            'deploy_type' => $deployType,
            'action' => $action,
            'header' => $header,
            'body' => $body
        ];

        RequestRecord::create($requestRecord);
    }

    /**
     * 资源创建的情况
     *
     * @param integer $code k8s资源创建状态信息，参考config/code.php文件
     * @param string $deploy_type 部署程序中哪个环节ingress，deployment
     * @param string $uniqid 资源的uniqid
     * @param string $details 具体细节,比如失败的exception的信息
     * @param bool $needString 返回数组或string, 默认为数组
     * @return array|null
     */
    public function package_params($code, $deploy_type, $uniqid, $details, $needString = false)
    {
        $data = [
            "code"            => $code,
            "deploy_type"     => $deploy_type,
            "uniqid"          => $uniqid,
            "details"         => $details,
            'occurrence_time' => date('Y-m-d H:i:s'),
        ];
        if ($needString) {
            $data = json_encode($data);
        }
        return $data;
    }

    /**
     * get corresponding real item by type and uniqid
     *
     * @param string $deploy_type
     * @param string $uniqid
     * @return Cluster|DataMigration|Deployment|Memcached|Rabbitmq|K8sNamespace|ModelConfig|Mysql|MysqlDatabase|Workspace|Mongodb|MongodbDatabase|\App\Redis|null
     */
    public function getItem($deploy_type, $uniqid)
    {
        if ($deploy_type == 'cluster') {
            $realItem = Cluster::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'namespace') {
            $realItem = K8sNamespace::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'deployment') {
            $realItem = Deployment::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'workspace') {
            $realItem = Workspace::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'model_config') {
            $realItem = ModelConfig::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'data_migration') {
            $realItem = DataMigration::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'mysql') {
            $realItem = Mysql::where(['uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'mysql_database') {
            $realItem = MysqlDatabase::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'mongodb') {
            $realItem = Mongodb::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'mongodb_database') {
            $realItem = MongodbDatabase::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'redis') {
            $realItem = Redis::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'memcached') {
            $realItem = Memcached::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'rabbitmq') {
            $realItem = Rabbitmq::where([ 'uniqid' => $uniqid])->first();
        } else if ($deploy_type == 'task') {
            $realItem = Task::where([ 'uniqid' => $uniqid])->first();
        } else {
            $realItem = null;
        }

        return $realItem;
    }
}
