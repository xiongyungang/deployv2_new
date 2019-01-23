<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Cluster
 *
 * @property int                                                               $id
 * @property string                                                            $appkey
 * @property string                                                            $name
 * @property string                                                            $area
 * @property string                                                            $server
 * @property string                                                            $domain
 * @property string                                                            $certificate_authority_data
 * @property string                                                            $username
 * @property string                                                            $client_certificate_data
 * @property string                                                            $client_key_data
 * @property string|null                                                       $operator_type
 * @property string|null                                                       $data_migration_info
 * @property \Carbon\Carbon|null                                               $created_at
 * @property \Carbon\Carbon|null                                               $updated_at
 * @property int                                                               $channel
 * @property string                                                            $uniqid
 * @property string                                                            $state
 * @property string                                                            $desired_state
 * @property int                                                               $attempt_times
 * @property string                                                            $message
 * @property string                                                            $callback_url
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\K8sNamespace[] $namespaces
 * @property-read \App\TaskItem                                                $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereArea($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereCertificateAuthorityData($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereClientCertificateData($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereClientKeyData($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereDataMigrationInfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereDomain($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereOperatorType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereServer($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Cluster whereUsername($value)
 * @mixin \Eloquent
 */
class Cluster extends Model
{
    protected $fillable = [
        'name',
        'appkey',
        'channel',
        'uniqid',
        'area',
        'server',
        'domain',
        'username',
        'certificate_authority_data',
        'client_certificate_data',
        'client_key_data',
        'operator_type',
        'data_migration_info',
        'state',
        'desired_state',
        'attempt_times',
        'message',
        'callback_url',
    ];
    
    protected $attributes = [
        'data_migration_info' => '',
        'state' => '',
        'desired_state' => '',
        'attempt_times' => 0,
        'message' => '',
        'callback_url' => '',
    ];
    
    public function namespaces()
    {
        return $this->hasMany('App\K8sNamespace');
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
