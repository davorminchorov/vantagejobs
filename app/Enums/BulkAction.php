<?php

declare(strict_types=1);

namespace App\Enums;

enum BulkAction: string
{
    case CHANGE_TIER = 'change_tier';
    case TAG = 'tag';
    case UNTAG = 'untag';
    case DISMISS = 'dismiss';
    case BOOKMARK = 'bookmark';
    case MARK_CLOSED = 'mark_closed';
}
