<?php

namespace App\Http\Controllers;

use App\Models\TaskList;
use App\Support\TaskData;
use Illuminate\Http\Request;

class TaskListController extends Controller
{
    public function store(Request $request)
    {
        $data = TaskData::validateList($request->all());
        $list = TaskList::create($data);

        return redirect()->route('tasks.index', ['list' => $list->id])->with('success', "Lista «{$list->name}» creada.");
    }

    public function update(Request $request, TaskList $taskList)
    {
        $data = TaskData::validateList($request->all(), $taskList);
        $taskList->update($data);

        return redirect()->route('tasks.index', ['list' => $taskList->id])->with('success', 'Lista renombrada.');
    }

    /** Las tareas de la lista no se borran: pasan a "General". */
    public function destroy(TaskList $taskList)
    {
        $taskList->delete();

        return redirect()->route('tasks.index')->with('success', "Lista «{$taskList->name}» eliminada; sus tareas pasaron a General.");
    }
}
