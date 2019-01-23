<?php

namespace App\Http\Controllers;

use App\Cluster;
use App\Jobs\DeployClusterJob;
use App\Rules\EditForbidden;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClusterController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function getAll(Request $request)
    {
        $validationData = \Validator::validate(array_merge(['page' => 1, 'limit' => 10], $request->all()), [
            'appkey' => 'required|string',
            'channel' => 'sometimes|required|integer',
            'area' => 'sometimes|required|string',
            'operator_type' => 'sometimes|required|string|in:develop,test,production',
            'page' => 'required|integer|gt:0',
            'limit' => 'required|integer|in:10,20,50',
        ], [
            'limit.in' => 'limit should be one of 10,20,50',
        ]);
        $page = $validationData['page'];
        unset($validationData['page']);
        $perPage = $validationData['limit'];
        unset($validationData['limit']);
        $clusters = Cluster::where($validationData)->forPage($page, $perPage)->get();
        return response()->json(['ret' => 1, 'data' => $clusters]);
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
            'uniqid' => 'required|string|unique:clusters,uniqid,NULL,id,appkey,'
                . $request->post('appkey') . ',channel,' . $request->post('channel'),
            'area' => 'required|string',
            'server' => 'required|string',
            'domain' => 'required|string',
            'username' => 'required|string',
            'certificate_authority_data' => 'required|string',
            'client_certificate_data' => 'required|string',
            'client_key_data' => 'required|string',
            'operator_type' => 'required|string',
            'data_migration_info' => 'sometimes|required|string',
            'callback_url' => 'sometimes|required',
        ]);
        do {
            $name = uniqid('cl-');
        } while (Cluster::whereName($name)->count() > 0);
        $validationData['name'] = $name;
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $cluster = Cluster::create($validationData);
        DeployClusterJob::dispatch($cluster);
        return response()->json(['ret' => 1, 'data' => $cluster]);
    }
    
    /**
     * @param Request $request
     * @param Cluster $cluster
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Cluster $cluster)
    {
        \Validator::validate(
            $request->all(),
            ['appkey' => 'required|in:' . $cluster->appkey],
            ['appkey.in' => 'The Cluster is not owned by token appkey']
        );
        return response()->json(['ret' => 1, 'data' => $cluster]);
    }
    
    /**
     * @param Request $request
     * @param Cluster $cluster
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Cluster $cluster)
    {
        
        $validationData = \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $cluster->appkey,
            'channel' => ['sometimes', new EditForbidden()],
            'uniqid' => ['sometimes', new EditForbidden()],
            'area' => 'sometimes|required|string',
            'domain' => 'sometimes|required|string',
            'username' => 'sometimes|required|string',
            'server' => ['sometimes', new EditForbidden()],
            'certificate_authority_data' => ['sometimes', new EditForbidden()],
            'client_certificate_data' => ['sometimes', new EditForbidden()],
            'client_key_data' => ['sometimes', new EditForbidden()],
            'operator_type' => ['sometimes', new EditForbidden()],
            'data_migration_info' => ['sometimes', new EditForbidden()],
            'callback_url' => 'sometimes|required',
        ], [
            'appkey.in' => 'The Cluster is not owned by token appkey',
        ]);
        $validationData['state'] = config('state.pending');
        $validationData['desired'] = config('state.restarted');
        $cluster->update($validationData);
        DeployClusterJob::dispatch($cluster);
        return response()->json(['ret' => 1, 'data' => $cluster]);
    }
    
    /**
     * @param Request $request
     * @param Cluster $cluster
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Request $request, Cluster $cluster)
    {
        \Validator::validate(
            $request->all(),
            ['appkey' => 'required|in:' . $cluster->appkey],
            ['appkey.in' => 'The Cluster is not owned by token appkey']
        );
        
        if ($cluster->namespaces->isNotEmpty()) {
            return response()->json(['ret' => -1, 'data' => $cluster]);
        }
        
        $cluster->state = config('state.pending');
        $cluster->desired_state = config('state.destroyed');
        $cluster->update();
        DeployClusterJob::dispatch($cluster);
        return response()->json(['ret' => 1, 'data' => $cluster]);
    }
}
