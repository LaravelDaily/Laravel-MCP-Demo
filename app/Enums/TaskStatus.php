<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';

    case InProgress = 'in_progress';

    case Done = 'done';
}
