<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Memcached;
use App\K8sNamespace;
use Illuminate\Validation\Rule;
use App\Jobs\DeployMemcachedJob;
use App\Rules\EditForbidden;


class MemcachedController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Memcached           $memcached
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Memcached $memcached)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $memcached->appkey], [
                'appkey.in' => 'The Memcached is not owned by token appkey',
            ]);
        $memcached->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $memcached]);
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
                'uniqid'  => 'sometimes|required|string',
                'channel' => 'sometimes|required|integer',
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
        $memcached = Memcached::where($validationData)
            ->forPage($page, $perPage)
            ->get()
            ->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $memcached]);
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
            'uniqid'         => "required|unique:memcacheds,uniqid",
            'channel'        => 'required|integer',
            'namespace_id'   => [
                'required',
                'integer',
                Rule::exists('namespaces', 'id'),
            ],
            'replicas'       => 'sometimes|required|integer|between:1,5',
            'username'       => 'sometimes|required|string',
            'password'       => 'sometimes|required|string',
            'port'           => 'sometimes|required|integer',
            'labels'         => 'sometimes|required|json',
            'cpu_request'    => 'sometimes|required|string',
            'cpu_limit'      => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit'   => 'sometimes|required|string',
            //todo memcached 暂不支持 pvc
            'storages'       => ['sometimes', new EditForbidden()],
            'callback_url'   => 'sometimes|required',
        ]);
        do {
            $name = uniqid('md-');
        } while (Memcached::whereName($name)->count() > 0);
        $k8sNamespace = K8sNamespace::find($request->post('namespace_id'));
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['name'] = $name;
        $validationData['host'] = $name . "." . $k8sNamespace->name;
        
        $memcached = Memcached::create($validationData);
        DeployMemcachedJob::dispatch($memcached);
        return response()->json(['ret' => 1, 'data' => $memcached]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Memcached           $memcached
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Memcached $memcached)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey'         => 'required|in:' . $memcached->appkey,
            'channel'        => 'sometimes|required|integer',
            'uniqid'         => [
                'sometimes',
                'required',
                'integer',
                Rule::unique('memcacheds', 'uniqid')
                    ->ignore($memcached->id)->where('appkey', $request->post('appkey'))
                    ->where('channel', $request->post('channel')),
            ],
            //todo:暂时不支持namespace改变
            'namespace_id'   => ['sometimes', new EditForbidden()],
            'replicas'       => 'sometimes|required|integer|between:1,5',
            'username'       => 'sometimes|required|string',
            'password'       => 'sometimes|required|string',
            'port'           => 'sometimes|required|integer',
            'labels'         => 'sometimes|required|json',
            'cpu_request'    => 'sometimes|required|string',
            'cpu_limit'      => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit'   => 'sometimes|required|string',
            //todo:暂时不支持storages改变,Kubernetes 1.11 版本才支持PVC 的扩容,并且暂时memcached不支持pvc
            'storages'       => ['sometimes', new EditForbidden()],
            'callback_url'   => 'sometimes|required',
        ], [
            'appkey.in' => 'The Memcached is not owned by token appkey',
        ]);
        
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        $memcached->update($validationData);
        DeployMemcachedJob::dispatch($memcached);
        return response()->json(['ret' => 1, 'data' => $memcached]);
    }
    
    /**
     * @param Request   $request
     * @param Memcached $memcached
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function start(Request $request, Memcached $memcached)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $memcached->appkey,], [
            'appkey.in' => 'The Memcached is not owned by token appkey',
        ]);
        
        if (!in_array($memcached->state, [config('state.failed'), config('state.stopped')])) {
            return response()->json(['ret' => -1, 'message' => "$memcached->name state is $memcached->state , can not start"]);
        }
        $memcached->state = config('state.pending');
        $memcached->desired_state = config('state.restarted');
        $memcached->update();
        DeployMemcachedJob::dispatch($memcached);
        return response()->json(['ret' => 1, 'data' => $memcached]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Memcached           $memcached
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function restart(Request $request, Memcached $memcached)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $memcached->appkey,], [
            'appkey.in' => 'The Memcached is not owned by token appkey',
        ]);
        $memcached->state = config('state.pending');
        $memcached->desired_state = config('state.restarted');
        $memcached->update();
        DeployMemcachedJob::dispatch($memcached);
        return response()->json(['ret' => 1, 'data' => $memcached]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Memcached           $memcached
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function stop(Request $request, Memcached $memcached)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $memcached->appkey], [
            'appkey.in' => 'The Memcached is not owned by token appkey',
        ]);
        if (!in_array($memcached->state, [config('state.started'), config('state.restarted')])) {
            return response()->json(['ret' => -1, 'message' => "$memcached->name state is $memcached->state , can not stop "]);
        }
        $memcached->state = config('state.pending');
        $memcached->desired_state = config('state.stopped');
        $memcached->update();
        DeployMemcachedJob::dispatch($memcached);
        return response()->json(['ret' => 1, 'data' => $memcached]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Memcached           $memcached
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, Memcached $memcached)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $memcached->appkey], [
                'appkey.in' => 'The Memcached is not owned by token appkey',
            ]);
        $memcached->state = config('state.pending');
        $memcached->desired_state = config('state.destroyed');
        $memcached->update();
        DeployMemcachedJob::dispatch($memcached);
        return response()->json(['ret' => 1, 'data' => $memcached]);
    }
}