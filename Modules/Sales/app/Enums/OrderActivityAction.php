<?php

namespace Modules\Sales\Enums;

enum OrderActivityAction: string
{
    case CREATED           = 'CREATED';
    case PAID              = 'PAID';
    case PROCESS           = 'PROCESS';
    case PICK_STARTED      = 'PICK_STARTED';
    case PICK_FAILED       = 'PICK_FAILED';
    case FINISH_PICK       = 'FINISH_PICK';
    case PACK_STARTED      = 'PACK_STARTED';
    case LABEL_PRINTED     = 'LABEL_PRINTED';
    case FINISH_PACK       = 'FINISH_PACK';
    case READY_TO_SHIP     = 'READY_TO_SHIP';
    case DRIVER_CALLED     = 'DRIVER_CALLED';
    case TRACKING_UPDATED  = 'TRACKING_UPDATED';
    case CHANNEL_STATUS    = 'CHANNEL_STATUS';
    case RECEIVED_BY_BUYER = 'RECEIVED_BY_BUYER';
    case RETURN_DECISION   = 'RETURN_DECISION';
    case FIELD_CHANGED     = 'FIELD_CHANGED';
    case SHIPPED           = 'SHIPPED';
    case COMPLETED         = 'COMPLETED';
    case CANCELLED         = 'CANCELLED';
    case ZONE_ASSIGNED     = 'ZONE_ASSIGNED';
    case ITEM_CREATED      = 'ITEM_CREATED';

    public function code(): string
    {
        return match ($this) {
            self::CREATED           => '100',
            self::PAID              => '120',
            self::PROCESS           => '200',
            self::PICK_STARTED      => '500',
            self::PICK_FAILED       => '510',
            self::FINISH_PICK       => '600',
            self::PACK_STARTED      => '700',
            self::LABEL_PRINTED     => '750',
            self::FINISH_PACK       => '800',
            self::READY_TO_SHIP     => '850',
            self::DRIVER_CALLED     => '870',
            self::TRACKING_UPDATED  => '900',
            self::CHANNEL_STATUS    => '910',
            self::RECEIVED_BY_BUYER => '913',
            self::RETURN_DECISION   => '920',
            self::FIELD_CHANGED     => '990',
            self::SHIPPED           => '999',
            self::COMPLETED         => '912',
            self::CANCELLED         => '000',
            self::ZONE_ASSIGNED     => '860',
            self::ITEM_CREATED      => '101',
        };
    }

    public static function fromCode(string $code): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->code() === $code) {
                return $case;
            }
        }
        return null;
    }
}
