<?php

namespace Modules\Inventory\Enums;

enum InventoryMovementType: string
{
    case PURCHASE          = 'PURCHASE';
    case SALES             = 'SALES';
    case ADJUSTMENT_IN     = 'ADJUSTMENT_IN';
    case ADJUSTMENT_OUT    = 'ADJUSTMENT_OUT';
    case TRANSFER_IN       = 'TRANSFER_IN';
    case TRANSFER_OUT      = 'TRANSFER_OUT';
    case TRANSIT_IN        = 'TRANSIT_IN';
    case TRANSIT_OUT       = 'TRANSIT_OUT';
    case PUTAWAY           = 'PUTAWAY';
    case PICK              = 'PICK';
    case RETURN_IN         = 'RETURN_IN';
    case RETURN_OUT        = 'RETURN_OUT';
    case BIN_MOVE          = 'BIN_MOVE';
    case BIN_MOVE_REVERSAL = 'BIN_MOVE_REVERSAL';
    case PICK_REVERSAL     = 'PICK_REVERSAL';
    case OPNAME            = 'OPNAME';

    public function isReversal(): bool
    {
        return str_ends_with($this->value, '_REVERSAL');
    }

    public function direction(): string
    {
        return match ($this) {
            self::PURCHASE, self::ADJUSTMENT_IN, self::TRANSFER_IN,
            self::TRANSIT_IN, self::PUTAWAY, self::RETURN_IN => 'IN',
            self::SALES, self::ADJUSTMENT_OUT, self::TRANSFER_OUT,
            self::TRANSIT_OUT, self::PICK, self::RETURN_OUT => 'OUT',
            default => 'NEUTRAL',
        };
    }
}
