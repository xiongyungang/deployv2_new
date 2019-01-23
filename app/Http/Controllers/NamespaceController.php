<?php

namespace App\Http\Controllers;

use App\Cluster;
use App\K8sNamespace;
use App\Jobs\DeployNamespaceJob;
use App\Rules\EditForbidden;
use Illuminate\Http\Request;

class NamespaceController extends Controller
{
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
                'appkey' => 'required|string',
                'channel' => 'sometimes|required|integer',
                'page' => 'required|integer|gt:0',
                'limit' => 'required|integer|in:10,20,50',
            ],
            [
                'limit.in' => 'limit should be one of 10,20,50',
            ]
        );
        $page = $validationData['page'];
        $perPage = $validationData['limit'];
        unset($validationData['page']);
        unset($validationData['limit']);
        $namespaces = K8sNamespace::where($validationData)->forPage($page, $perPage)->get();
        return response()->json(['ret' => 1, 'data' => $namespaces]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey' => 'required|string',
            'channel' => 'required|integer',
            'uniqid' => 'required|string|unique:namespaces,uniqid,NULL,id,appkey,'
                . $request->post('appkey') . ',channel,' . $request->post('channel'),
            'cluster_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $cluster = Cluster::find($value);
                    if (!$cluster) {
                        return $fail('Cluster not exist');
                    }
                    if ($cluster->appkey != \request('appkey')) {
                        return $fail('The Namespace is not created by token appkey');
                    }
                },
            ],
            'callback_url' => 'sometimes|required',
            'cpu_request' => 'sometimes|required|string',
            'cpu_limit' => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit' => 'sometimes|required|string',
            'storages' => 'sometimes|required|string',
            //TODO:目前只上传一个私有仓库，以后可能是多个，待完善
            'docker_registry' => 'required|array',
            'docker_registry.name' => 'required|string',
            'docker_registry.server' => 'required|string',
            'docker_registry.username' => 'required|string',
            'docker_registry.password' => 'required|string',
            'docker_registry.email' => 'required|string',
        ]);
        do {
            $name = uniqid('ns-');
        } while (K8sNamespace::whereName($name)->count() > 0);
        $validationData['name'] = $name;
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['docker_registry'] = json_encode($validationData['docker_registry']);
        $namespace = K8sNamespace::create($validationData);
        DeployNamespaceJob::dispatch($namespace);
        return response()->json(['ret' => 1, 'data' => $namespace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\K8sNamespace        $namespace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, K8sNamespace $namespace)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $namespace->appkey]);
        return response()->json(['ret' => 1, 'data' => $namespace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\K8sNamespace        $namespace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, K8sNamespace $namespace)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $namespace->appkey,
            'channel' => ['sometimes', new EditForbidden()],
            'uniqid' => ['sometimes', new EditForbidden()],
            'cluster_id' => ['sometimes', new EditForbidden()],
            'callback_url' => 'sometimes|required',
            'cpu_request' => 'sometimes|required|string',
            'cpu_limit' => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit' => 'sometimes|required|string',
            'storages' => 'sometimes|required|string',
            //TODO:目前只上传一个私有仓库，以后可能是多个，待完善
            'docker_registry' => 'sometimes|required|array',
            'docker_registry.name' => 'required_with:docker_registry|string',
            'docker_registry.server' => 'required_with:docker_registry|string',
            'docker_registry.username' => 'required_with:docker_registry|string',
            'docker_registry.password' => 'required_with:docker_registry|string',
            'docker_registry.email' => 'required_with:docker_registry|string',
        ], [
            'appkey.in' => 'The Namespace is not owned by token appkey',
        ]);
        if (isset($validationData['docker_registry'])) {
            $validationData['docker_registry'] = json_encode($validationData['docker_registry']);
        }
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        $namespace->update($validationData);
        DeployNamespaceJob::dispatch($namespace);
        return response()->json(['ret' => 1, 'data' => $namespace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\K8sNamespace        $namespace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function destroy(Request $request, K8sNamespace $namespace)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $namespace->appkey,
        ], [
            'appkey.in' => 'The Namespace is not owned by token appkey',
        ]);
        
        $namespace->state = config('state.pending');
        $namespace->desired_state = config('state.destroyed');
        $namespace->update();
        DeployNamespaceJob::dispatch($namespace);
        return response()->json(['ret' => 1, 'data' => $namespace]);
    }
    
}
