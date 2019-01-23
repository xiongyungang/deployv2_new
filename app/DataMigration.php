<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * App\DataMigration
 *
 * @property int                 $id
 * @property string              $name
 * @property string              $appkey
 * @property int                 $channel
 * @property string              $uniqid
 * @property string              $type
 * @property int                 $src_instance_id
 * @property int                 $dst_instance_id
 * @property string              $labels
 * @property string              $state
 * @property string              $desired_state
 * @property string              $callback_url
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read \App\TaskItem  $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereDstInstanceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereSrcInstanceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\DataMigration whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class DataMigration extends Model
{
    protected $table = 'data_migrations';
    protected $fillable = [
        'name',
        'appkey',
        'channel',
        'uniqid',
        'type',
        'src_instance_id',
        'dst_instance_id',
        'labels',
        'state',
        'desired_state',
        'callback_url',
    ];
    
    protected $attributes = [
        'labels' => '{}',
    ];

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
