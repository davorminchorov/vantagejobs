<?php

declare(strict_types=1);

namespace App\Enums;

enum EventType: string
{
    case NEW_ROLE = 'new_role';
    case ROLE_CLOSED = 'role_closed';
    case ROLE_REOPENED = 'role_reopened';
    case ROLE_UPDATED = 'role_updated';
    case COMPANY_ADDED = 'company_added';
    case NOTE_ADDED = 'note_added';
}
