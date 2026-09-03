<?php

namespace App\Modules\Payroll\Support;

use RuntimeException;

/**
 * Thrown when payroll finalization is attempted while a database transaction is
 * already open. Finalization is the authoritative financial commit and MUST own a
 * top-level REPEATABLE READ transaction it opens itself — it may never silently
 * degrade to a nested savepoint that inherits READ COMMITTED. This is an
 * infrastructure fault (a caller wrapped finalization in a transaction), not a
 * user/domain error, so it is deliberately unchecked and fails the request closed.
 */
class NestedFinalizationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payroll finalization must run at the top transaction level (no enclosing transaction).');
    }
}
