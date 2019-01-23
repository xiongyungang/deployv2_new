<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Yaml\Yaml;


/**
 * App\Mongodb
 *
 * @property int                                                                  $id
 * @property string                                                               $appkey
 * @property string                                                               $uniqid
 * @property int                                                                  $channel
 * @property int                                                                  $namespace_id
 * @property int                                                                  $replicas
 * @property string                                                               $name
 * @property int                                                                  $port
 * @property string                                                               $host_write
 * @property string                                                               $host_read
 * @property string                                                               $username
 * @property string                                                               $password
 * @property string                                                               $state
 * @property string                                                               $desired_state
 * @property string                                                               $labels
 * @property string                                                               $cpu_limit
 * @property string                                                               $cpu_request
 * @property string                                                               $memory_limit
 * @property string                                                               $memory_request
 * @property string                                                               $storages
 * @property int                                                                  $attempt_times
 * @property string                                                               $message
 * @property \Carbon\Carbon|null                                                  $created_at
 * @property \Carbon\Carbon|null                                                  $updated_at
 * @property string                                                               $callback_url
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\MongodbDatabase[] $databases
 * @property-read \App\K8sNamespace                                               $namespace
 * @property-read \App\TaskItem                                                   $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereHostRead($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereHostWrite($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereNamespaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb wherePort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereReplicas($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Mongodb whereUsername($value)
 * @mixin \Eloquent
 */
class Mongodb extends Model
{
    public $table = 'mongodbs';
    
    protected $fillable = [
        'name',
        'appkey',
        'channel',
        'uniqid',
        'namespace_id',
        'replicas',
        'port',
        'host_write',
        'host_read',
        'username',
        'password',
        'labels',
        'cpu_limit',
        'cpu_request',
        'memory_limit',
        'memory_request',
        'storages',
        'state',
        'desired_state',
        'attempt_times',
        'message',
        'callback_url',
    ];
    
    protected $attributes = [
        'replicas'       => 2,
        'port'           => 27017,
        'username'       => 'root',
        'labels'         => '{}',
        'cpu_limit'      => '500m',
        'cpu_request'    => '1m',
        'memory_limit'   => '512Mi',
        'memory_request' => '1Mi',
        'storages'       => '5Gi',
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
        return $this->hasMany('App\MongodbDatabase');
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
