<?php
/**
 * Created by PhpStorm.
 * User: zhoumiao
 * Date: 13/07/2018
 * Time: 6:30 PM
 */

use GuzzleHttp\Client;

if (!function_exists('custom_exec')) {
    function custom_exec($command)
    {
        $result = exec($command, $output, $return_var);
        $ret = [
            'commnad' => $command,
            'result' => $result,
            'output' => $output,
            'return_var' => $return_var,
        ];

        //Log::info(print_r($ret));
        return $ret;
    }
}
/**
 * @return null|string
 * @parms \GuzzleHttp\Exception\RequestException
 */
if (!function_exists('requestAsync_post')) {
    function requestAsync_post($url, $type, $messages, $params)
    {
        try {
            Log::info('send callback_url' . $url);

            if (!$url) {
                Log::warning('callback_url is null');
                return null;
            }
            $data = package_params($type, $messages, $params);
            if (!$data) {
                return;
            }
            $client = new Client([
                // You can set any number of default request options.
                'timeout' => 1.0,
            ]);
            $response = $client->request('POST', $url, [
                'headers' => [
                    "Accept" => "application/json"
                ],
                'form_params' => $data
            ]);

            $body = $response->getBody();
            Log::info("requset callback_url body :" . $body);
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            Log::error($exception->getMessage());
            return;
        }
    }
}

if (!function_exists('package_params')) {
    function package_params($type, $messages, $params)
    {
        if (!$type || !$params) {
            Log::warning("params type or params is null , please check it!");
            return null;
        }
        if (!isset($messages['logs'])) {
            array_merge($messages, ['logs' => ""]);
        }
        $data = [
            "type" => $type,
            "data" => $params,
            "messages" => $messages
        ];

        return $data;
    }
}
