<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\Redis
 *
 * @property int                    $id
 * @property string                 $appkey
 * @property string                 $uniqid
 * @property int                    $channel
 * @property int                    $namespace_id
 * @property int                    $replicas
 * @property string                 $name
 * @property string                 $host_write
 * @property string                 $host_read
 * @property string                 $password
 * @property int                    $port
 * @property string                 $labels
 * @property string                 $cpu_limit
 * @property string                 $cpu_request
 * @property string                 $memory_limit
 * @property string                 $memory_request
 * @property string                 $storages
 * @property int                    $attempt_times
 * @property string                 $message
 * @property string                 $state
 * @property string                 $desired_state
 * @property \Carbon\Carbon|null    $created_at
 * @property \Carbon\Carbon|null    $updated_at
 * @property string                 $callback_url
 * @property-read \App\K8sNamespace $namespace
 * @property-read \App\TaskItem     $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereHostRead($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereHostWrite($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereNamespaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis wherePort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereReplicas($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Redis whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Redis extends Model
{
    public $table = 'redises';
    
    protected $fillable = [
        'appkey',
        'uniqid',
        'channel',
        'namespace_id',
        'replicas',
        'name',
        'host_write',
        'host_read',
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
    
    protected $attributes = [
        'replicas'       => 1,
        'password'       => '123456',
        'port'           => 6379,
        'labels'         => '{}',
        'cpu_limit'      => '200m',
        'cpu_request'    => '1m',
        'memory_limit'   => '256Mi',
        'memory_request' => '1Mi',
        'storages'       => '',
        'attempt_times'  => 0,
        'message'        => '',
        'callback_url'   => '',
    ];
    
    public function namespace()
    {
        return $this->belongsTo('App\K8sNamespace');
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
