<?php
/**
 * Created by PhpStorm.
 * User: root
 * Date: 18-11-12
 * Time: 上午10:15
 */

return [
    /*
     *  operators info
     */
    'types' => [
        'aliyun' => [
            'name' => 'aliyun',
            'registry_address' => 'registry-vpc.cn-shanghai.aliyuncs.com',
            'image_pull_secret_name' => 'aliyun-registry-vpc',
        ],
        'qcloud' => [
            'name' => 'qcloud',
            'registry_address' => 'registry.cn-shanghai.aliyuncs.com',
            'image_pull_secret_name' => 'aliyun-registry',
        ]
    ],

    /*
     *  default operator name
     */
    'default' =>'aliyun',

];