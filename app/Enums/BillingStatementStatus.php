<?php

namespace App\Enums;

/**
 * Payment state of a generated billing statement. Set by the Super Admin.
 */
enum BillingStatementStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Void = 'void';
}
