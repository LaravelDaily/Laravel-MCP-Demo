<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';

    case InProgress = 'in_progress';

    case Done = 'done';

    /**
     * Values of the statuses that count as unfinished work.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Todo->value, self::InProgress->value];
    }
}
