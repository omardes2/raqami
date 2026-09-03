<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase-1 hardening (post-review):
 *
 * 1. The effective-range overlap triggers now acquire the SAME transaction-scoped
 *    advisory lock the application services take (PayrollLock::compensationNamespace
 *    / componentNamespace), BEFORE the overlap SELECT. Without this, two concurrent
 *    DIRECT-SQL writers could each read no committed conflict and both insert an
 *    overlapping effective range. Locking inside the trigger closes that race for
 *    every write path — service or raw SQL — since both serialize on the identical
 *    pg_advisory_xact_lock(hashtextextended(namespace, 0)) key. pg_advisory_xact_lock
 *    is re-entrant, so a service call that already holds the lock is unaffected.
 *
 * 2. A DB CHECK enforces that a payroll_period is a FULL calendar month even under
 *    direct SQL that bypasses PayrollPeriodService (period_start = first of month,
 *    period_end = last day of that same month).
 *
 * No btree_gist, no new extension. CREATE OR REPLACE keeps published history intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Trigger namespaces below MUST match PayrollLock::compensationNamespace()
        // and PayrollLock::componentNamespace() byte-for-byte.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_prevent_compensation_overlap()
            RETURNS trigger AS $$
            DECLARE
                lock_ns text := 'payroll:compensation:' || NEW.tenant_id || ':' || NEW.employee_id;
            BEGIN
                -- Serialize concurrent writers for this (tenant, employee) before the
                -- overlap check — identical key to PayrollLock::forCompensation().
                PERFORM pg_advisory_xact_lock(hashtextextended(lock_ns, 0));

                IF EXISTS (
                    SELECT 1 FROM employee_compensations e
                    WHERE e.tenant_id = NEW.tenant_id
                      AND e.employee_id = NEW.employee_id
                      AND e.id <> NEW.id
                      AND NEW.effective_from <= COALESCE(e.effective_to, DATE '9999-12-31')
                      AND e.effective_from <= COALESCE(NEW.effective_to, DATE '9999-12-31')
                ) THEN
                    RAISE EXCEPTION 'overlapping employee_compensations effective range for employee %', NEW.employee_id
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION raqmi_prevent_comp_component_overlap()
            RETURNS trigger AS $$
            DECLARE
                lock_ns text := 'payroll:compensation_component:' || NEW.tenant_id || ':' || NEW.employee_id || ':' || NEW.payroll_component_id;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended(lock_ns, 0));

                IF EXISTS (
                    SELECT 1 FROM employee_compensation_components c
                    WHERE c.tenant_id = NEW.tenant_id
                      AND c.employee_id = NEW.employee_id
                      AND c.payroll_component_id = NEW.payroll_component_id
                      AND c.id <> NEW.id
                      AND NEW.effective_from <= COALESCE(c.effective_to, DATE '9999-12-31')
                      AND c.effective_from <= COALESCE(NEW.effective_to, DATE '9999-12-31')
                ) THEN
                    RAISE EXCEPTION 'overlapping employee_compensation_components range for employee % component %', NEW.employee_id, NEW.payroll_component_id
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // Full-calendar-month backstop for payroll_periods (immutable date math).
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_full_month_chk CHECK (
                period_start = date_trunc('month', period_start::timestamp)::date
                AND period_end = (date_trunc('month', period_start::timestamp) + interval '1 month' - interval '1 day')::date
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payroll_periods DROP CONSTRAINT IF EXISTS payroll_periods_full_month_chk');

        // Restore the pre-hardening (lock-free) overlap trigger functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_prevent_compensation_overlap()
            RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM employee_compensations e
                    WHERE e.tenant_id = NEW.tenant_id
                      AND e.employee_id = NEW.employee_id
                      AND e.id <> NEW.id
                      AND NEW.effective_from <= COALESCE(e.effective_to, DATE '9999-12-31')
                      AND e.effective_from <= COALESCE(NEW.effective_to, DATE '9999-12-31')
                ) THEN
                    RAISE EXCEPTION 'overlapping employee_compensations effective range for employee %', NEW.employee_id
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION raqmi_prevent_comp_component_overlap()
            RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM employee_compensation_components c
                    WHERE c.tenant_id = NEW.tenant_id
                      AND c.employee_id = NEW.employee_id
                      AND c.payroll_component_id = NEW.payroll_component_id
                      AND c.id <> NEW.id
                      AND NEW.effective_from <= COALESCE(c.effective_to, DATE '9999-12-31')
                      AND c.effective_from <= COALESCE(NEW.effective_to, DATE '9999-12-31')
                ) THEN
                    RAISE EXCEPTION 'overlapping employee_compensation_components range for employee % component %', NEW.employee_id, NEW.payroll_component_id
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
