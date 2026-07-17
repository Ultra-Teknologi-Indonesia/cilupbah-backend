<?php

namespace Modules\Sales\Enums;

enum ShippingLabelDocType: string
{
    case PDF = 'PDF';
    case ZPL = 'ZPL';
    case PNG = 'PNG';
    case JPG = 'JPG';
}
