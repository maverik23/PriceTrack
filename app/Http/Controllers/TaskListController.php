<?php

namespace App\Http\Controllers;

use App\Models\TaskList;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskListController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:task_lists,name']], [], ['name' => 'nombre de la lista']);
        $list = TaskList::create($data);

        return redirect()->route('tasks.index', ['list' => $list->id])->with('success', "Lista «{$list->name}» creada.");
    }

    public function update(Request $request, TaskList $taskList)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('task_lists', 'name')->ignore($taskList)],
        ], [], ['name' => 'nombre de la lista']);
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
