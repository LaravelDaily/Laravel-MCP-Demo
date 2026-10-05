<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';

    case Member = 'member';

    case Viewer = 'viewer';

    /**
     * Owners and members may create and edit tasks, assign them, change
     * their status, and comment. Viewers are read-only.
     */
    public function canContribute(): bool
    {
        return in_array($this, [self::Owner, self::Member], true);
    }
}
