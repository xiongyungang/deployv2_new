<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\DataMigration;
use App\Jobs\DeployDataMigrationJob;
class DataMigrationController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */

    public function create(Request $request)
    {
        $validationData=\Validator::validate($request->all(), [
            'appkey' => "required|string",
            'channel' => "required|integer",
            'uniqid' => "required|string",
            'type' => "required|string",
            'src_instance_id' => [
                'required', 'integer',
                Rule::exists($this->getTableName($request->get('type')), 'id')
            ],
            'dst_instance_id' => [
                'required', 'integer',
                Rule::exists($this->getTableName($request->get('type')), 'id')
            ],
            'callback_url'=>"sometimes|required|string",
            'labels' => 'sometimes|required|string',
        ]);

        do {
            $name = uniqid('dm-');
        } while (DataMigration::whereName($name)->count() > 0);

        $validationData['name'] = $name;
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $dataMigration = DataMigration::create($validationData);

        DeployDataMigrationJob::dispatch($dataMigration);
        return response()->json(['ret'=>1,'data'=>$dataMigration]);
    }

    /**
     * @param \App\DataMigration           $dataMigration
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     *
     */
    public function update(Request $request,DataMigration $dataMigration)
    {
        return response()->json(['ret' => 1, 'message' => 'this api temporary disuse']);
        /*
        $validationData=\Validator::validate($request->all(), [
            'app_id' => [
                "sometimes",'required', 'integer',
                Rule::exists('apps', 'id')
            ],
            'repo_id' => [
                "sometimes",'required', 'integer',
                Rule::exists('repos', 'id'),
            ],
            'commit' => "sometimes|required|string",
            'command' => "sometimes|required|string",
            'envs' => "sometimes|required|string",
            'callback_url' => 'sometimes|required|string',
            'labels' => 'sometimes|required|string',
        ]);

        if ($dataMigration->state == config('state.pending')) {
            return response()->json([
                'ret' => -1,
                'msg' => 'dataMigration' . $dataMigration->id . ' is Pending to ' . $dataMigration->desired_state,
            ]);
        }
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        $dataMigration->update($validationData);
        //todo:更新job
        DeployDataMigrationJob::dispatch($dataMigration,true);
        return response()->json(['ret' => 1, 'data' => $dataMigration]);
        */
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

        $dataMigrations = DataMigration::where($validationData)
            ->forPage($page, $perPage)
            ->get();

        return response()->json(['ret' => 1, 'data' => $dataMigrations]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\DataMigration           $dataMigration
     * @return \Illuminate\Http\JsonResponse
     */
    public function get(Request $request, DataMigration $dataMigration)
    {
        return response()->json(['ret' => 1, 'data' => $dataMigration]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\DataMigration           $dataMigration
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, DataMigration $dataMigration)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $dataMigration->appkey]);

        if ($dataMigration->state == config('state.pending')) {
            return response()->json([
                'ret' => -1,
                'msg' => 'dataMigration ' . $dataMigration->id . ' is Pending to ' . $dataMigration->desired_state,
            ]);
        }

        $dataMigration->update([
            'state' => config('state.pending'),
            'desired_state' => config('state.destroyed'),
        ]);

        DeployDataMigrationJob::dispatch($dataMigration);
        return response()->json(['ret' => 1, 'data' => $dataMigration]);
    }

    public static function getTableName($type)
    {
        if($type == 'mysql') {
            return 'databases';
        } else {
            return $type;
        }
    }

}
