<?php

namespace App\Enums;

enum AssignmentActionEnum: string
{
    case ASSIGN = 'ASSIGN';
    case REASSIGN = 'REASSIGN';
    case UNASSIGN = 'UNASSIGN';
    case SELF_UNASSIGN = 'SELF_UNASSIGN';
    case FORCE_RESET = 'FORCE_RESET';
}
