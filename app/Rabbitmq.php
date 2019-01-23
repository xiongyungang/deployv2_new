<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\Rabbitmq
 *
 * @property int                    $id
 * @property string                 $appkey
 * @property string                 $uniqid
 * @property int                    $channel
 * @property int                    $namespace_id
 * @property int                    $replicas
 * @property string                 $name
 * @property string                 $host
 * @property string                 $username
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
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereNamespaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq wherePort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereReplicas($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Rabbitmq whereUsername($value)
 * @mixin \Eloquent
 */
class Rabbitmq extends Model
{
    protected $fillable = [
        'appkey',
        'uniqid',
        'channel',
        'namespace_id',
        'replicas',
        'name',
        'host',
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
    
    protected $attributes = [
        'replicas'       => 3,
        'username'       => 'root',
        'port'           => 5672,
        'labels'         => '{}',
        'callback_url'   => '',
        'cpu_limit'      => '200m',
        'cpu_request'    => '1m',
        'memory_limit'   => '256Mi',
        'memory_request' => '1Mi',
        'storages'       => '1Gi',
        'attempt_times'  => 0,
        'message'        => '',
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
