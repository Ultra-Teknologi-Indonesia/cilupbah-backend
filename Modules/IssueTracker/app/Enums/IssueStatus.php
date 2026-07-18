<?php

namespace Modules\IssueTracker\Enums;

enum IssueStatus: string
{
    case OPEN        = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESOLVED    = 'RESOLVED';
    case CLOSED      = 'CLOSED';
    case REOPENED    = 'REOPENED';
}
