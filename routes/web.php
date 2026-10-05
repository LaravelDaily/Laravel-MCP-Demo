<?php

use App\Livewire\Dashboard;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectShow;
use App\Livewire\Tasks\Show as TaskShow;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('projects', ProjectsIndex::class)->name('projects.index');

    Route::livewire('projects/{projectId}', ProjectShow::class)->whereNumber('projectId')->name('projects.show');

    Route::livewire('tasks/{taskId}', TaskShow::class)->whereNumber('taskId')->name('tasks.show');
});

require __DIR__.'/settings.php';
