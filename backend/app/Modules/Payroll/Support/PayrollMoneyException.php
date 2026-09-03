<?php

namespace App\Modules\Payroll\Support;

use RuntimeException;

/**
 * Raised when a payroll money operation cannot be performed safely — a
 * non-positive denominator, or a multiplication that would overflow PHP's
 * native integer. Never allow a silent overflow to corrupt a financial figure.
 */
class PayrollMoneyException extends RuntimeException {}
