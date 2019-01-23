<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Yaml\Yaml;


/**
 * App\Mysql
 *
 * @property int                                                                $id
 * @property string                                                             $appkey
 * @property string                                                             $uniqid
 * @property int                                                                $channel
 * @property int                                                                $namespace_id
 * @property int                                                                $replicas
 * @property string                                                             $name
 * @property string                                                             $host_write
 * @property string                                                             $host_read
 * @property string                                                             $username
 * @property string                                                             $password
 * @property int                                                                $port
 * @property string                                                             $labels
 * @property string                                                             $cpu_limit
 * @property string                                                             $cpu_request
 * @property string                                                             $memory_limit
 * @property string                                                             $memory_request
 * @property string                                                             $storages
 * @property int                                                                $attempt_times
 * @property string                                                             $message
 * @property string                                                             $state
 * @property string                                                             $desired_state
 * @property \Carbon\Carbon|null                                                $created_at
 * @property \Carbon\Carbon|null                                                $updated_at
 * @property string                                                             $callback_url
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\MysqlDatabase[] $databases
 * @property-read \App\K8sNamespace                                             $namespace
 * @property-read \App\TaskItem                                                 $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereHostRead($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereHostWrite($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereNamespaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql wherePort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereReplicas($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mysql whereUsername($value)
 * @mixin \Eloquent
 */
class Mysql extends Model
{
    protected $fillable = [
        'appkey',
        'channel',
        'uniqid',
        'namespace_id',
        'replicas',
        'name',
        'host_write',
        'host_read',
        'username',
        'password',
        'port',
        'labels',
        'cpu_limit',
        'cpu_request',
        'memory_limit',
        'memory_request',
        'storages',
        'attempt_times',
        'message',
        'state',
        'desired_state',
        'callback_url',
    ];
    
    //todo: xtrabackup容器的cpu_requests暂时定为1m，节约cpu
    protected $attributes = [
        'replicas'       => 1,
        'username'       => 'root',
        'port'           => 3306,
        'labels'         => '{}',
        'cpu_limit'      => '500m',
        'cpu_request'    => '1m',
        'memory_limit'   => '256Mi',
        'memory_request' => '1Mi',
        'storages'       => '10Gi',
        'attempt_times'  => 0,
        'message'        => '',
        'callback_url'   => '',
    ];
    
    public function namespace()
    {
        return $this->belongsTo('App\K8sNamespace');
    }
    
    public function databases()
    {
        return $this->hasMany('App\MysqlDatabase');
    }
    
    public function valuesFile()
    {
        $file = '/tmp/values-mysql-' . $this->id;
        
        $values = [
            'labels'       => [
                'appkey'  => $this->appkey,
                'channel' => $this->channel,
                'name'    => $this->name,
            ],
            'customLabels' => [
            
            ],
            'mysqlha'      => [
                'mysqlRootPassword' => $this->password,
                'mysqlUser'         => $this->username,
                'mysqlPassword'     => $this->password,
                'mysqlDatabase'     => $this->name,
            ],
        ];
        if ($this->labels) {
            $customLabels = json_decode($this->labels, true);
            $values['customLabels'] = $customLabels ?: [];
        }
        file_put_contents($file, Yaml::dump($values, 10, 2));
        
        return $file;
        
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
