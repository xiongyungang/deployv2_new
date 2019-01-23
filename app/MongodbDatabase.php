<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\MongodbDatabase
 *
 * @property int                 $id
 * @property string              $appkey
 * @property string              $uniqid
 * @property int                 $channel
 * @property int                 $mongodb_id
 * @property string              $name
 * @property string              $database_name
 * @property string              $username
 * @property string              $password
 * @property string              $labels
 * @property int                 $attempt_times
 * @property string              $message
 * @property string              $state
 * @property string              $desired_state
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string              $callback_url
 * @property-read \App\Mongodb   $mongodb
 * @property-read \App\TaskItem  $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereDatabaseName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereMongodbId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MongodbDatabase whereUsername($value)
 * @mixin \Eloquent
 */
class MongodbDatabase extends Model
{
    protected $table = 'mongodb_databases';
    
    protected $fillable = [
        "appkey",
        "channel",
        "uniqid",
        'mongodb_id',
        'name',
        'database_name',
        'username',
        'password',
        'labels',
        "attempt_times",
        "message",
        'state',
        'desired_state',
        'callback_url',
    ];
    protected $attributes = [
        'labels'   => '{}',
        'attempt_times'  => 0,
        'message'        => '',
        'callback_url'   => '',
    ];
    
    public function mongodb()
    {
        return $this->belongsTo('App\Mongodb');
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
