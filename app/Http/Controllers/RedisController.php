<?php

namespace App\Http\Controllers;

use App\K8sNamespace;
use Illuminate\Http\Request;
use App\Redis;
use Illuminate\Validation\Rule;
use App\Jobs\DeployRedisJob;
use App\Rules\EditForbidden;

class RedisController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Redis               $redis
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Redis $redis)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $redis->appkey]);
        $redis->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $redis]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function getAll(Request $request)
    {
        $validationData = \Validator::validate(
            array_merge(['page' => 1, 'limit' => 10], $request->all()),
            [
                'appkey'  => 'required|string',
                'channel' => 'sometimes|required|integer',
                'uniqid'  => 'sometimes|required|string',
                'page'    => 'required|integer|gt:0',
                'limit'   => 'required|integer|in:10,20,50',
            ],
            [
                'limit.in' => 'limit should be one of 10,20,50',
            ]
        );
        $page = $validationData['page'];
        $perPage = $validationData['limit'];
        unset($validationData['page']);
        unset($validationData['limit']);
        $redis = Redis::where($validationData)
            ->forPage($page, $perPage)
            ->get()
            ->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $redis]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        $validationData = \Validator::validate(
            $request->all(), [
            'appkey'         => 'required|string',
            'uniqid'         => "required|unique:redises,uniqid",
            'channel'        => 'required|integer',
            'namespace_id'   => [
                'required',
                'integer',
                Rule::exists('namespaces', 'id'),
            ],
            'replicas'       => 'sometimes|required|integer|between:1,5',
            'password'       => 'sometimes|required|string',
            'port'           => 'sometimes|required|integer',
            'labels'         => 'sometimes|required|json',
            'cpu_request'    => 'sometimes|required|string',
            'cpu_limit'      => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit'   => 'sometimes|required|string',
            'storages'       => 'sometimes|required|string',
            'callback_url'   => 'sometimes|required',
        ]);
        do {
            $name = uniqid('re-');
        } while (Redis::whereName($name)->count() > 0);
        $Namespace = K8sNamespace::find($request->post('namespace_id'));
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['name'] = $name;
        $validationData['host_write'] = $name . "-0." . $name . "." . $Namespace->name;
        $validationData['host_read'] = $name . "-ro." . $name . "." . $Namespace->name;
        
        $redis = Redis::create($validationData);
        DeployRedisJob::dispatch($redis);
        return response()->json(['ret' => 1, 'data' => $redis]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Redis               $redis
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Redis $redis)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey'         => 'required|in:' . $redis->appkey,
            'channel'        => 'sometimes|required|integer',
            'uniqid'         => [
                'sometimes',
                'required',
                'integer',
                Rule::unique('redises', 'uniqid')
                    ->ignore($redis->id)->where('appkey', $request->post('appkey'))
                    ->where('channel', $request->post('channel')),
            ],
            //todo:暂时不支持namespace改变
            'namespace_id'   => ['sometimes', new EditForbidden()],
            'replicas'       => 'sometimes|required|integer|between:1,5',
            'password'       => 'sometimes|required|string',
            'port'           => 'sometimes|required|integer',
            'labels'         => 'sometimes|required|json',
            'cpu_request'    => 'sometimes|required|string',
            'cpu_limit'      => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit'   => 'sometimes|required|string',
            //todo:暂时不支持storages改变,Kubernetes 1.11 版本才支持PVC 的扩容
            'storages'       => ['sometimes', new EditForbidden()],
            'callback_url'   => 'sometimes|required',
        ], [
            'appkey.in' => 'The Redis is not owned by token appkey',
        ]);
        
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        $redis->update($validationData);
        DeployRedisJob::dispatch($redis);
        return response()->json(['ret' => 1, 'data' => $redis]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Redis               $redis
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function restart(Request $request, Redis $redis)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $redis->appkey], [
            'appkey.in' => 'The Redis is not owned by token appkey',
        ]);
        
        $redis->state = config('state.pending');
        $redis->desired_state = config('state.restarted');
        $redis->update();
        DeployRedisJob::dispatch($redis);
        return response()->json(['ret' => 1, 'data' => $redis]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Redis               $redis
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function start(Request $request, Redis $redis)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $redis->appkey], [
            'appkey.in' => 'The Redis is not owned by token appkey',
        ]);
        
        if (!in_array($redis->state, [config('state.failed'), config('state.stopped')])) {
            return response()->json(['ret' => -1, 'message' => "$redis->name state is $redis->state , can not start"]);
        }
        $redis->state = config('state.pending');
        $redis->desired_state = config('state.restarted');
        $redis->update();
        DeployRedisJob::dispatch($redis);
        return response()->json(['ret' => 1, 'data' => $redis]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Redis               $redis
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function stop(Request $request, Redis $redis)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $redis->appkey], [
            'appkey.in' => 'The Redis is not owned by token appkey',
        ]);
        if (!in_array($redis->state, [config('state.started'), config('state.restarted')])) {
            return response()->json(['ret' => -1, 'message' => "$redis->name state is $redis->state , can not stop "]);
        }
        $redis->state = config('state.pending');
        $redis->desired_state = config('state.stopped');
        $redis->update();
        DeployRedisJob::dispatch($redis);
        return response()->json(['ret' => 1, 'data' => $redis]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Redis               $redis
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, Redis $redis)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $redis->appkey], [
            'appkey.in' => 'The Redis is not owned by token appkey',
        ]);
        $redis->state = config('state.pending');
        $redis->desired_state = config('state.destroyed');
        $redis->update();
        DeployRedisJob::dispatch($redis);
        return response()->json(['ret' => 1, 'data' => $redis]);
    }
}
