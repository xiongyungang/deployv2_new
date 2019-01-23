<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Class TaskRecord
 * @package App
 *
 * @property int                    $id
 * @property string                 $deploy_type
 * @property string                 $action
 * @property string                 $header
 * @property string                 $body
 * @property \Carbon\Carbon|null    $created_at
 * @property \Carbon\Carbon|null    $updated_at
 * @mixin \Eloquent
 */
class RequestRecord extends Model
{
    protected $fillable = [
        'deploy_type',
        'action',
        'header',
        'body',
    ];

    protected $attributes = [
        'deploy_type' => null,
        'action' => null,
        'header' => null,
        'body' => '',
    ];
}
