<?php

use App\Livewire\Dashboard;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectShow;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('projects', ProjectsIndex::class)->name('projects.index');

    Route::livewire('projects/{projectId}', ProjectShow::class)->name('projects.show');
});

require __DIR__.'/settings.php';
