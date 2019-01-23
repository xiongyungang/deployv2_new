<?php

namespace App\Http\Controllers;

use App\Cluster;
use App\Jobs\DeployWorkspaceJob;
use App\K8sNamespace;
use App\Rules\EditForbidden;
use App\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
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
        unset($validationData['appkey']);
        $workspaces = Workspace::where($validationData)->forPage($page, $perPage)->get();
        return response()->json(['ret' => 1, 'data' => $workspaces]);
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
            'uniqid' => 'required|string|unique:workspaces,uniqid,NULL,id,appkey,'
                . $request->post('appkey') . ',channel,' . $request->post('channel'),
            'namespace_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $namespace = K8sNamespace::find($value);
                    if (!$namespace) {
                        return $fail('Namespace not exist');
                    }
                    if ($namespace->appkey != \request('appkey')) {
                        return $fail('The Namespace is not created by token appkey');
                    }
                },
            ],
            'image_url' => 'required|string',
            'need_https' => 'sometimes|required|integer|in:0,1',
            'envs' => 'sometimes|required|json',
            'labels' => 'sometimes|required|json',
            'callback_url' => 'sometimes|required',
            'ssh_private_key' => 'required|string',
            'cpu_request' => 'sometimes|required|string',
            'cpu_limit' => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit' => 'sometimes|required|string',
            'storages' => 'required|string',
            'preprocess_info' => 'required|array',
            'preprocess_info.git_private_key' => 'required_with:preprocess_info|string',
        ]);
        
        do {
            $name = uniqid('ws-');
        } while (Workspace::whereName($name)->count() > 0);
        
        $namespace = K8sNamespace::find($validationData['namespace_id']);
        $cluster = Cluster::find(K8sNamespace::find(\request('namespace_id'))->cluster_id);
        $validationData['name'] = $name;
        $validationData['preprocess_info'] = json_encode($validationData['preprocess_info']);
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['hostname'] = $name . '.' . $namespace->name;
        $validationData['host'] = $name . '.' . $cluster->domain;
        $workspace = Workspace::create($validationData);
        DeployWorkspaceJob::dispatch($workspace);
        return response()->json(['ret' => 1, 'data' => $workspace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Workspace           $workspace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Workspace $workspace)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $workspace->appkey,
        ], [
            'appkey.in' => 'The Workspace is not owned by token appkey',
        ]);
        $workspace->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $workspace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Workspace           $workspace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Workspace $workspace)
    {
        if ($workspace->desired_state == config('state.destroyed')) {
            return response()->json(['ret' => -1, 'data' => $workspace]);
        }
        $validationData = \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $workspace->appkey,
            'channel' => 'required|integer',
            'uniqid' => [
                'sometimes',
                'required',
                'integer',
                Rule::unique('workspaces', 'uniqid')
                    ->ignore($workspace->id)->where('appkey', $request->post('appkey'))
                    ->where('channel', $request->post('channel')),
            ],
            'namespace_id' => ['sometimes', new EditForbidden()],
            'ssh_private_key' => 'sometimes|required|string',
            'cpu_request' => 'sometimes|required|string',
            'cpu_limit' => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit' => 'sometimes|required|string',
            'storages' => ['sometimes', new EditForbidden()],
            'preprocess_info' => 'sometimes|required|array',
            'preprocess_info.git_private_key' => 'required_with:preprocess_info|string',
            'image_url' => 'sometimes|required|string',
            'need_https' => 'sometimes|required|integer|in:0,1',
            'envs' => 'sometimes|required|json',
            'labels' => 'sometimes|required|json',
            'callback_url' => "sometimes|required",
        ], [
            'appkey.in' => 'The Workspace is not owned by token appkey',
        ]);
        if (isset($validationData['preprocess_info'])) {
            $validationData['preprocess_info'] = json_encode($validationData['preprocess_info']);
        }
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        $workspace->update($validationData);
        DeployWorkspaceJob::dispatch($workspace);
        return response()->json(['ret' => 1, 'data' => $workspace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Workspace           $workspace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function start(Request $request, Workspace $workspace)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $workspace->appkey,
        ], [
            'appkey.in' => 'The Workspace is not owned by token appkey',
        ]);
        
        if ($workspace->state != config('state.stopped') && $workspace->state != config('state.destroyed')) {
            return response()->json(['ret' => -1, 'data' => $workspace]);
        }
        
        $workspace->state = config('state.pending');
        $workspace->desired_state = config('state.restarted');
        $workspace->update();
        DeployWorkspaceJob::dispatch($workspace);
        return response()->json(['ret' => 1, 'data' => $workspace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Workspace           $workspace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function stop(Request $request, Workspace $workspace)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $workspace->appkey,
        ], [
            'appkey.in' => 'The Workspace is not owned by token appkey',
        ]);
        if ($workspace->state != config("state.started") && $workspace->state != config("state.restarted")) {
            return response()->json(['ret' => -1, 'data' => $workspace]);
        }
        $workspace->state = config('state.pending');
        $workspace->desired_state = config('state.stopped');
        $workspace->update();
        DeployWorkspaceJob::dispatch($workspace);
        return response()->json(['ret' => 1, 'data' => $workspace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Workspace           $workspace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function restart(Request $request, Workspace $workspace)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $workspace->appkey,
        ], [
            'appkey.in' => 'The Workspace is not owned by token appkey',
        ]);
        if ($workspace->state != config("state.started")) {
            return response()->json(['ret' => -1, 'data' => $workspace]);
        }
        $workspace->state = config('state.pending');
        $workspace->desired_state = config('state.restarted');
        $workspace->update();
        DeployWorkspaceJob::dispatch($workspace);
        return response()->json(['ret' => 1, 'data' => $workspace]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Workspace           $workspace
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, Workspace $workspace)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $workspace->appkey,
        ], [
            'appkey.in' => 'The Workspace is not owned by token appkey',
        ]);
        $workspace->state = config('state.pending');
        $workspace->desired_state = config('state.destroyed');
        $workspace->update();
        DeployWorkspaceJob::dispatch($workspace);
        return response()->json(['ret' => 1, 'data' => $workspace]);
    }
}
