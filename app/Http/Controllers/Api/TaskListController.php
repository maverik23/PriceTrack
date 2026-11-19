<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\TaskList;
use App\Support\TaskData;
use Illuminate\Http\Request;

/** Listas de tareas («General» = sin lista). */
class TaskListController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => TaskList::withCount(['tasks' => fn ($q) => $q->pending()])->orderBy('name')->get()->map(fn (TaskList $l) => Present::taskList($l))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $list = TaskList::create(TaskData::validateList($request->all()));

        return response()->json(['data' => Present::taskList($list), 'message' => "Lista «{$list->name}» creada."], 201);
    }

    public function update(Request $request, TaskList $taskList)
    {
        $taskList->update(TaskData::validateList($request->all(), $taskList));

        return response()->json(['data' => Present::taskList($taskList), 'message' => 'Lista renombrada.']);
    }

    /** Sus tareas no se borran: pasan a «General». */
    public function destroy(TaskList $taskList)
    {
        $taskList->delete();

        return response()->json(['message' => "Lista «{$taskList->name}» eliminada; sus tareas pasaron a General."]);
    }
}
