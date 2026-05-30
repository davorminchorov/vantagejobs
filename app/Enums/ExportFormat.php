<?php

declare(strict_types=1);

namespace App\Enums;

enum ExportFormat: string
{
    case SQL = 'sql';
    case JSON = 'json';
    case CSV = 'csv';
}
