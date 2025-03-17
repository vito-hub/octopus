app/Models/Task/TaskDetail
app/Models/Task/TaskTodo
app/Models/CRM/CrmProjectActivity
app/Models/Meeting
<?php

namespace App\Http\Controllers;

use App\Models\AttachFile;
use App\Models\CoinBank;
use App\Models\Module;
use App\Models\Task\Task;
use App\Models\Task\TaskComment;
use App\Models\Task\TaskDetail;
use App\Models\Task\TaskRelProject;
use App\Models\Task\TaskRelTag;
use App\Models\Task\TaskTodo;
use App\Models\Task\TaskTodoRoutine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Inertia\Response;

class TaskRequestController extends Controller
{

    public function storeTask()
    {
        try {
            DB::beginTransaction();

            $data = request()->validate([
                'ta_title' => 'required|string',
                'ta_priority' => 'required|string|in:high,medium,low',
                'ta_importance' => 'required|integer',
                'ta_urgency' => 'required|integer',
                'ta_difficulty' => 'required|integer',
                'ta_is_forwardable' => 'required|boolean',
                'ta_description' => 'nullable|string',
                'ta_routine_period' => 'nullable|string|in:daily,monthly,daily',
                'multipleTags' => 'nullable|array',
                'multipleTags.*.tart_tag_id' => 'integer|exists:tag,id',
                'detail' => 'required|array',
                'detail.*.tade_assigned_to' => 'required|integer',
                'detail.*.tade_status' => 'required|in:open,in_progress,done,cancel,forward',
                'detail.*.tade_start' => 'required|date',
                'detail.*.tade_end' => 'required|date',
                'detail.*.tade_coin' => 'required|integer',
                'detail.*.limit' => 'nullable|integer',
                'attachment' => 'nullable|array',
                'attachment.*' => 'file|max:10240',
                'todoId' => 'nullable|integer',
            ]);

            $data['created_by'] = auth()->id();

            $createdTask = Task::create($data);

            if (!$createdTask || !$createdTask->id) {
                throw new \Exception('Task creation failed or invalid task ID.');
            }


            if (!empty($data['multipleTags'])) {
                foreach ($data['multipleTags'] as $tag) {
                    if (!Tag::find($tag['tart_tag_id'])) {
                        throw new \Exception("Tag with ID {$tag['tart_tag_id']} does not exist.");
                    }
                    TaskRelTag::create([
                        'tart_tag_id' => $tag['tart_tag_id'],
                        'tart_task_id' => $createdTask->id,
                    ]);
                }
            }

            $this->handleTaskDetail($createdTask->id, $data['detail'] , $createdTask);
            if (isset($data['attachment'])) {
                $this->handleFileAttachments($data['attachment'], $createdTask->id);
            }

            if (!empty($data['todoId'])){
                TaskTodo::where('id', $data['todoId'])->delete();
            }
            DB::commit();
            if (\request('module_id') === 10){
                session()->flash('success', 'Saved successfully!');
            }else{
                return $createdTask;
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Task creation failed: ' . $e->getMessage());
            session()->flash('error', 'You have an Error: ' . $e->getMessage());
        }
    }

    private function handleTaskDetail($taskId, $data, $createdTask)
    {
        foreach ($data as $oneData) {
            $validatedData = Validator::validate($oneData, [
                'tade_assigned_to' => 'required|integer',
                'tade_seen_at' => 'nullable|date',
                'tade_status' => 'required|in:open,in_progress,done,cancel,forward',
                'tade_start' => 'required|date',
                'tade_end' => 'required|date',
                'tade_final_action_at' => 'nullable|date',
                'tade_quality_rate' => 'nullable|integer|min:1|max:5',
                'tade_owner_action' => 'nullable|integer|in:1,2,3',
                'tade_owner_action_at' => 'nullable|date',
                'tade_coin' => 'required|integer',
                'limit' => 'nullable|integer',
            ]);

            if (!empty($createdTask->ta_routine_period)) {
                $startDate = Carbon::parse($oneData['tade_start']);
                $endDate = Carbon::parse($oneData['tade_end']);

                $taskDetailData = [
                    'tade_task_id' => $taskId,
                    'tade_assigned_to' => $validatedData['tade_assigned_to'],
                    'tade_status' => $validatedData['tade_status'],
                    'tade_seen_at' => $validatedData['tade_seen_at'] ?? null,
                    'tade_final_action_at' => $validatedData['tade_final_action_at'] ?? null,
                    'tade_quality_rate' => $validatedData['tade_quality_rate'] ?? null,
                    'tade_owner_action' => $validatedData['tade_owner_action'] ?? null,
                    'tade_owner_action_at' => $validatedData['tade_owner_action_at'] ?? null,
                    'tade_coin' => $validatedData['tade_coin'],
                ];

                while ($startDate ->lte($endDate)) {
                    $currentStartDate = clone $startDate;
                    $currentEndDate = clone $endDate;
                    $limit= $oneData['limit'];

                    switch ($createdTask->ta_routine_period) {
                        case 'daily':
                            TaskDetail::create(array_merge($taskDetailData, [
                                'tade_start' => $currentStartDate->toDateString(),
                                'tade_end' => $currentStartDate->addDays($limit)->toDateString()
                            ]));
                            $startDate->addDay();
                            break;

                        case 'weekly':
                            TaskDetail::create(array_merge($taskDetailData, [
                                'tade_start' => $currentStartDate->toDateString(),
                                'tade_end' => $currentStartDate->addDays($limit)->toDateString()
                            ]));
                            $startDate->addWeek();

                            break;

                        case 'monthly':
                            TaskDetail::create(array_merge($taskDetailData, [
                                'tade_start' => $currentStartDate->toDateString(),
                                'tade_end' => $currentStartDate->addDays($limit)->toDateString()
                            ]));
                            $startDate->addMonth();
                            break;

                    }
                }
            } else {
                $validatedData['tade_task_id'] = $taskId;
                TaskDetail::create($validatedData);
            }
        }
    }

    private function handleFileAttachments($files, $taskId)
    {
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $filename = $file->store('task-attachments', 'local');
            $module = Module::where('name', 'tasks')->firstOrFail();

            AttachFile::create([
                'af_file_name' => $filename,
                'af_module' => $module->id,
                'af_sender_id' => auth()->id(),
                'af_entity_id' => $taskId,
                'af_file_path' => dirname($filename),
                'af_file_type' => $file->getMimeType(),
                'af_file_size' => $file->getSize(),
                'af_model_name' => 'App/Models/Task/Task.php',
            ]);
        }
    }

    public function storeTodo():void
    {
        try{
            switch(request('action')){
                case 'add':
                    $columns= request()->validate([
                        "tato_title"=>"required|string|max:255",
                        "tato_routine_period"=> "nullable|string|in:daily,weekly,monthly",
                        "start"=> "nullable|date",
                        "end"=>"nullable|date",
                    ]);
                    $columns['created_by']= auth()->id();
                    $routine = $columns['tato_routine_period'];
                    $start_date = Carbon::parse($columns['start']);
                    $end_date = Carbon::parse($columns['end']);
                    $Created_Todo=TaskTodo::create($columns);

                    if($routine == 'daily'){
                        while($start_date->lte($end_date)){
                            TaskTodoRoutine::create([
                                'tatr_todo_id' => $Created_Todo['id'],
                                'tatr_routine_end_date' => $start_date->toDateString(),
                            ]);
                            $start_date->addDay();
                        }

                    }elseif($routine == 'weekly'){
                        while($start_date->lte($end_date)){
                            TaskTodoRoutine::create([
                                'tatr_todo_id' => $Created_Todo['id'],
                                'tatr_routine_end_date' => $start_date->endOfWeek()->toDateString(),
                            ]);
                            $start_date->addWeek();
                        }
                    }elseif($routine == 'monthly'){
                        while($start_date->lte($end_date)){
                            TaskTodoRoutine::create([
                                'tatr_todo_id'=> $Created_Todo['id'],
                                'tatr_routine_end_date' => $start_date->endOfMonth()->toDateString(),
                            ]);
                            $start_date->addMonth();
                        }

                    }else {
                        throw new \Exception("Task not found.");
                    }
                    break;

                case 'edit':
                    $columns= request()->validate([
                        "tato_title"=>"required|string|max:255",
                        "tato_routine_period"=> "nullable|string|in:daily,weekly,monthly",
                        "start"=> "nullable|date",
                        "end"=>"nullable|date",
                    ]);
                    $title= $columns['tato_title'];
                    $task = TaskTodo::find(request()->id);
                    if ($task) {
                        $task->update([
                            "tato_title" => $title,
                        ]);
                    }
                    break;

                case "delete":

                    $tasks = collect(request('id'));
                    $IdsWithRoutine = $tasks->filter(function ($task) {
                        return $task['tato_routine_period'] !== null;
                    })->pluck('id')->toArray();
                    $IdsWithOutRoutine = $tasks->filter(function($task){
                        return $task["tato_routine_period"] === null;
                    })->pluck('id')->toArray();

                    if (!empty($IdsWithRoutine)) {
                        foreach ($IdsWithRoutine as $taskId) {
                            $remainingRoutines = TaskTodoRoutine::where('id', $taskId)->count();
                            if ($remainingRoutines === 0) {
                                TaskTodo::where('id', $taskId)->delete();
                            }
                            TaskTodoRoutine::whereIn('id', $IdsWithRoutine)->delete();
                        }
                    }
                    if(!empty($IdsWithOutRoutine)){
                        TaskTodo::whereIn('id', $IdsWithOutRoutine)->delete();
                    }
                    break;


                case "done":
                    $tasks = collect(request('id'));
                    $taskIdsWithRoutine = $tasks->filter(function($task){
                        return $task['tato_routine_period'] !== null;
                    })->pluck('id')->toArray();
                    if(!empty($taskIdsWithRoutine)){
                        TaskTodoRoutine::whereIn('id', $taskIdsWithRoutine)->update(['tatr_check' => 1]);
                    }else{
                        TaskTodo::whereIn('id', $tasks->pluck('id'))->update(['tato_done' => Carbon::now()]);
                    }
                    session()->flash('success', 'the operation was successful');
                    break;
                // undone
                case 'undone':
                    $tasks = collect(request('id'));
                    $taskIdsWithRoutine= $tasks->filter(function($task){
                        return $task['tato_routine_period'] !== null;
                    })->pluck('id')->toArray();
                    if(!empty($taskIdsWithRoutine)){
                        TaskTodoRoutine::whereIn('id', $taskIdsWithRoutine)->update(['tatr_check' => 0]);
                    }else{
                        TaskTodo::whereIn('id', $tasks->pluck('id'))->update(['tato_done' => null]);
                    }
                    Session()->flash('success', 'the tasks were undone successful');
                    break;
            }
        }catch(\Exception $e){
            db::rollBack();
            session()->flash('there is something wrong');
            Log::info('Data are corrupted or not verified.');
        }

    }

    public function changeTaskStatus(): void
    {
        try {
            $data = request()->validate([
                'tade_status' => 'required|in:open,in_progress,done,cancel,forward',
                'id' => 'required|integer',
                'tade_assigned_to' => 'nullable|integer',
            ]);
            DB::beginTransaction();
            $findTaskDetail = TaskDetail::find($data['id']);
            if (!$findTaskDetail) {
                session()->flash('error', 'Task not found!');
                return;
            }
            $findTaskDetail->update(['tade_status' => $data['tade_status']]);
            if ($data['tade_status'] === 'forward' && !empty($data['tade_assigned_to'])) {
                $findTaskDetail -> update(['tade_final_action_at' => now()]);
                $findMainTask = Task::find($findTaskDetail->tade_task_id);
                if($findMainTask){
                    $findMainTask->update(['ta_is_forwardable' => 1]);
                }
                TaskDetail::create([
                    'tade_task_id' => $findTaskDetail->tade_task_id,
                    'tade_parent_id' => $data['id'],
                    'tade_assigned_to' => $data['tade_assigned_to'],
                    'tade_status' => 'open',
                    'tade_start' => now(),
                    'tade_end' => $findTaskDetail->tade_end,
                    'tade_coin' => $findTaskDetail->tade_coin,
                ]);
                session()->flash('success', 'New task created!');
            }else {
                throw new Exception("Error we don't have assigned to!");
            }
            DB::commit();
            session()->flash('success', 'Your status successfully edited!');
        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Your error message is: ' . $e->getMessage());
            Log::error('Some errors for edit status for task: ', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => request()->all(),
            ]);
        }
    }

    public function RatingTask(): void
    {

    }

    public function confirmTask(): void
    {
        try {
            DB::beginTransaction();
            foreach (request()->all() as $data) {

                if ($data['confirm'] == 1) {
                    $validate = Validator::make($data, [
                        'task_detail_id' => 'required|integer',
                        'tade_quality_rate' => 'required|in:1,2,3,4,5',
                    ])->validate();
                    $findTaskDetail = TaskDetail::find($validate['task_detail_id']);
                    $findTaskDetail->update([
                        'tade_quality_rate' => $data['tade_quality_rate'],
                        'tade_owner_action' => auth()->id(),
                        'tade_owner_action_at' => now(),
                    ]);
                    $addCoin = CoinBank::firstOrCreate(
                        ['co_employee_id' => $findTaskDetail->tade_assigned_to],
                        ['co_remain' => 0, 'co_pending' => 0]
                    );
                    $addCoin->co_pending += $findTaskDetail->tade_coin;
                    $addCoin->save();

                    $getcoin = CoinBank::firstOrCreate(
                        ['co_employee_id' => auth()->id()],
                        ['co_remain' => 0, 'co_pending' => 0]
                    );
                    $getcoin->co_remain -= $findTaskDetail->tade_coin;
                    $getcoin->save();
                } elseif ((int)$data['confirm'] === 0) {

                    $validated = Validator::make($data, [
                        'task_detail_id' => 'required|integer',
                        'tade_assigned_to' => 'required|integer',
                        'tade_end' => 'required|date',
                    ])->validate();


                    // dd($data);
                    $find = TaskDetail::find($validated['task_detail_id']);


                    $find->update([
                        'tade_owner_action' => auth()->id(),
                        'tade_owner_action_at' => now(),
                    ]);
                    TaskDetail::create([
                        'tade_task_id' => $find->tade_task_id,
                        'tade_assigned_to' => $data['tade_assigned_to'],
                        'tade_seen_at' => null,
                        'tade_status' => 'open',
                        'tade_start' =>  now(),
                        'tade_end' => $data['tade_end'],
                        'tade_quality_rate' => null,
                        'tade_coin' => $find->tade_coin,
                    ]);
                } elseif ($data['confirm'] == null) {
                    $validate = $data->validate([
                        'task_detail_id' => 'required|integer',
                    ]);
                    $findTaskDetail = TaskDetail::find($validate['task_detail_id']);
                    $findTaskDetail->update([
                        'tade_status' => 'cancel',
                        'tade_owner_action' => auth()->id(),
                        'tade_owner_action_at' => now(),
                    ]);
                }
            }
            DB::commit();
            session()->flash('success', 'Successfully done!');
        } catch (\Exception $e) {
            DB::rollBack();
            session('error', 'You have some errors');
            Log::error('Error in confirmTask Function ', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => request()->all(),
            ]);
        }
    }

    public function taskComment()
    {
        try {
            DB::beginTransaction();
    
            $action = request('action');
            $message = "No action performed.";
    
            if ($action === 'add') {

                $commentData = request()->validate([
                    'taco_task_detail_id' => 'bail|required|integer',
                    'taco_task_id' => 'bail|required|integer',
                    'taco_comment' => 'bail|required|string|max:255',
                    'taco_cm_forward_id' => 'bail|nullable|integer',
                    'taco_cm_to_id' => 'bail|required|integer',
                    'taco_cm_seen_at' => 'bail|nullable|date',
                    'attachment'=> 'bail|nullable|array',
                    'attachment.*' => 'file|max:10240'
                ]);

                $comment = TaskComments::create($commentData);
    
                if(isset($commentData['attachment'])){
                    $this->handleFileAttachments($attachmentData, $comment->id);
                }

                $message = "Comment and/or attachment added successfully!";
            } elseif ($action === 'edit') {
                if (!empty(request("seen"))) {
                    foreach (request("seen") as $seen_data) {
                        TaskComments::where("id", $seen_data["id"])->update([
                            'taco_cm_seen_at' => now(),
                        ]);
                    }
                    $message = "Comment edited successfully!";
                }
            } else {
                throw new \Exception("Invalid action provided.");
            }
    
            DB::commit();
            return redirect()->back()->with("success", $message);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with("error", "There was an error: " . $e->getMessage());
        }
    }

    public function taskTimeDetect(): void
    {

    }

    public function taskSeen(): void
    {
        try{

            $frontId = request('id');
            $record = TaskDetail::find($frontId);
            if (!$record) {
                throw new \Exception ('Record not found.');
            }
            DB::beginTransaction();
            $record->update([
                'tade_seen_at' => now()
            ]);
            DB::commit();

        }catch(\Exception $e){

            session()->flash('error', 'Something went wrong. Error: ' . $e->getMessage());
        }
    }

    public function taskEdit(): void
    {
        try {
            DB::beginTransaction();

            $validatedData = Validator::validate(request()->all(), [
                'id' => 'required|integer|exists:task_details,id',
                'tade_end' => 'required|date',
                'ta_difficulty' => 'required|integer|in:1,2,3',
            ]);

            $taskDetail = TaskDetail::findOrFail($validatedData['id']);
            $task = Task::findOrFail($taskDetail->tade_task_id);


            auth()->id() === (int) $task->created_by ? $taskDetail->update(['tade_end' => $validatedData['tade_end']]) : throw new \Exception("This task is not assigned by you!");
            $task->update(['ta_difficulty' => $validatedData['ta_difficulty']]);

            DB::commit();
            session()->flash('success', 'Successfully changed!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in changeDeadlineAndDifficulty', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => request()->all(),
            ]);
            session()->flash('error', 'An error occurred: ' . $e->getMessage());
        }
    }
}



/////////////////

<?php

use App\Http\Controllers\Controller;
class new extends Controller
{
    public function taskTimeDetect(): void
    {
        try {
            DB::beginTransaction();
    
            $columns = request()->validate([
                'tatd_entity_id' => 'required|integer',
                'tatd_model_name' => 'nullable|string',
                'tatd_module_id' => 'required|integer',
                'tatd_start' => 'nullable|date',
                'tatd_end' => 'nullable|date',
            ]);
    
            $validModels = [
                'TaskDetail' => 'app/Models/Task/TaskDetail',
                'TaskTodo' => 'app/Models/Task/TaskTodo',
                'CrmProjectActivity' => 'app/Models/CRM/CrmProjectActivity',
                'Meeting' => 'app/Models/Meeting'
            ];
    
            if (!array_key_exists(request('tatd_model_name'), $validModels)) {
                throw new \Exception('Invalid tatd_model_name');
            }
    
            $columns['tatd_model_name'] = $validModels[request('tatd_model_name')];
    
            switch(request('action')) {
                case 'start':
                    $columns['tatd_start'] = Carbon::now();
                    TaskTimesheetDetect::create($columns);
                    break;
    
                case 'pause':
                    TaskTimesheetDetect::where('tatd_entity_id', $columns['tatd_entity_id'])->orderByDesc('id')->first()->update(['tatd_end'=> Carbon::now()]);

                    break;
    
                case 'stop':
                    
                    TaskTimesheetDetect::where('tatd_entity_id', $columns['tatd_entity_id'])->orderByDesc('id')->first()->update(['tatd_end'=> Carbon::now()]);

                    $dataForDuration = TaskTimesheetDetect::where('tatd_entity_id', $columns['tatd_entity_id'])->get();

                
                    $duration = null;
                    foreach ($dataForDuration as $key ) {
                        $end = $key->tatd_end;
                        $start = $key->tatd_start;
                        $duration += Carbon::parse($start)->diffInSeconds(Carbon::parse($end));
                    }

                    $pastData= TaskTimesheetDetect::where('tatd_entity_id', $columns['tatd_entity_id'])->get();
                    foreach ($pastData as $data) {
                        $data->delete();
                    }                    
                    TimesheetDetails::create([
                        'tide_parent_tiid' => request('tide_parent_tiid'),
                        'tide_parent_module' => $columns['tatd_model_name'],
                        'tide_entity_id' => $columns['tatd_entity_id'],
                        'tide_date' => now(),
                        'tide_start' => $start,
                        'tide_end' => now(),
                        'tide_duration' => $duration,
                        'tide_parent_tyid' => null,
                        'tide_created_by' => auth()->id(),
                        'created_at' => now(),
                    ]);
                    break;
            }
            DB::commit();
            session()->flash('success', 'TimeSheet created successfully.');
    
        } catch (\Exception $e) {
            DB::rollback();
            session()->flash('error', $e->getMessage());
            Log::error('Error saving TimeSheetDetect data.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => request()->all()
            ]);
        }
    
    }
}
--------- new detect------------------------


public function TimesheetDetects()
{
    try {
        DB::beginTransaction();

        $columns = request()->validate([
            'tatd_entity_id' => 'required|integer',
            'tatd_model_name' => ['nullable', 'string', Rule::in(['TaskDetail', 'TaskTodo', 'CrmProjectActivity', 'Meeting'])],
            'tatd_module_id' => 'required|integer',
            'tatd_start' => 'nullable|date',
            'tatd_end' => 'nullable|date',
        ]);

        
        $validModels = [
            'TaskDetail' => \App\Models\Task\TaskDetail::class,
            'TaskTodo' => \App\Models\Task\TaskTodo::class,
            'CrmProjectActivity' => \App\Models\CRM\CrmProjectActivity::class,
            'Meeting' => \App\Models\Meeting::class
        ];

        if (!empty($columns['tatd_model_name']) && !isset($validModels[$columns['tatd_model_name']])) {
            throw new \Exception('Invalid tatd_model_name');
        }

        $columns['tatd_model_name'] = $validModels[$columns['tatd_model_name']] ?? null;

        $action = request('action');

        switch ($action) {
            case 'start':
                $columns['tatd_start'] = now();
                TaskTimesheetDetects::create($columns);
                break;

            case 'pause':
                $lastEntry = TaskTimesheetDetects::where('tatd_entity_id', $columns['tatd_entity_id'])
                    ->orderByDesc('id')
                    ->first();

                if (!$lastEntry) {
                    throw new \Exception('No previous entry found to pause.');
                }

                $columns['tatd_start'] = $lastEntry->tatd_start;
                $columns['tatd_end'] = now();
                TaskTimesheetDetects::create($columns);
                break;

            case 'stop':
                $timesheet = TaskTimesheetDetects::where('tatd_entity_id', $columns['tatd_entity_id'])
                    ->whereNull('tatd_end')
                    ->orderByDesc('id')
                    ->first();

                if (!$timesheet || !$timesheet->tatd_start) {
                    throw new \Exception('Start time not found for tatd_entity_id: ' . $columns['tatd_entity_id']);
                }

                $ids = TaskTimesheetDetects::where('tatd_entity_id', $columns['tatd_entity_id'])->get();
                $duration= [];
                foreach( $ids as $key){
                    $stTime= $key -> tatd_start_time;
                    $enTime = $key -> tatd_end_time;
                    $duration = Carbon::parse($stTime)->diffInSeconds(Carbon::parse($enTime));
                }

                TimesheetDetails::create([
                    'tide_parent_tiid' => request('tide_parent_tiid'),
                    'tide_parent_module' => $columns['tatd_model_name'],
                    'tide_entity_id' => $columns['tatd_entity_id'],
                    'tide_date' => now(),
                    'tide_start' => $start_time,
                    'tide_end' => $end_time,
                    'tide_duration' => $duration,
                    'tide_parent_tyid' => null,
                    'tide_created_by' => auth()->id(),
                    'created_at' => now(),
                ]);

                TaskTimesheetDetects::where('tatd_entity_id', $columns['tatd_entity_id'])->delete();

                DB::commit();
                session()->flash('success', 'Timesheet entry completed and saved successfully.');
                return back();

            default:
                throw new \Exception('Invalid action provided.');
        }

        DB::commit();
        session()->flash('success', 'Timesheet action performed successfully.');
    } catch (\Exception $e) {
        DB::rollback();
        Log::error('Error saving TimesheetDetect data.', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'data' => request()->all(),
        ]);

        session()->flash('error', $e->getMessage());
    }

    return back();
}
