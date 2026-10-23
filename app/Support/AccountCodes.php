<?php

namespace App\Support;

/** Chart-of-accounts codes the posting logic depends on (these accounts are is_system and cannot be re-coded). */
final class AccountCodes
{
    public const CASH = '1000';

    public const BANK = '1010';

    public const RECEIVABLE = '1100';

    public const INVENTORY = '1200';

    public const INPUT_TAX = '1450';          // sales tax paid on purchases (receivable from FBR)

    public const EMPLOYEE_ADVANCE = '1460';

    public const PAYABLE = '2000';

    public const EMPLOYEE_PAYABLE = '2100';

    public const OUTPUT_TAX = '2400';         // sales tax collected on sales (payable to FBR)

    public const OWNER_CAPITAL = '3000';

    public const OWNER_DRAWINGS = '3100';

    public const RETAINED_EARNINGS = '3200';

    public const OPENING_EQUITY = '3900';

    public const SALES = '4000';

    public const SALES_RETURNS = '4100';

    public const SALES_DISCOUNT = '4200';

    public const COGS = '5000';

    public const INVENTORY_ADJUSTMENT = '5100';

    public const PROCESSING_CHARGES = '5150';

    public const SALARIES = '5200';

    public const OTHER_PAYABLES = '2600';
}
