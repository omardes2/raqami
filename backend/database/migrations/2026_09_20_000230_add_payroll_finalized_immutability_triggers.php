<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level immutability for FINALIZED / CLOSED payroll financial records (Phase 2B,
 * scope item 22). BEFORE-row triggers reject any UPDATE/DELETE (and, for generated
 * children, INSERT) that would mutate a finalized run, a finalized entry, that
 * entry's generated lines, an adjustment in a closed period, or a closed period —
 * regardless of the write path (Eloquent, service, or raw SQL). This is the last
 * line of defence UNDER the application finalization service, not a substitute for
 * it: tampering is impossible even for a direct-SQL writer.
 *
 * The forward transitions THEMSELVES are always allowed, because each trigger reads
 * the OLD (pre-transition) status: finalizing a `calculated` entry, finalizing an
 * `approved`/`calculated` run, and closing an `open` period all pass; only a row
 * that is ALREADY terminal is frozen. Generated lines are frozen by their parent
 * entry's status; adjustments are frozen once their run's period is closed.
 *
 * ERRCODE 'restrict_violation' (23001) lets callers distinguish an immutability
 * rejection from other failures. CREATE OR REPLACE + idempotent trigger drops keep
 * re-runs and published history safe. No new extension. PostgreSQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- A payroll_run that is finalized is frozen (no UPDATE/DELETE). The
            -- finalize transition passes because OLD.status is still approved/calculated.
            CREATE OR REPLACE FUNCTION raqmi_payroll_run_immutable_when_finalized()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'finalized' THEN
                    RAISE EXCEPTION 'payroll_run % is finalized and immutable', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            -- A payroll_entry that is finalized is frozen. The finalize transition
            -- passes because OLD.status is still 'calculated' at that moment.
            CREATE OR REPLACE FUNCTION raqmi_payroll_entry_immutable_when_finalized()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'finalized' THEN
                    RAISE EXCEPTION 'payroll_entry % is finalized and immutable', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            -- A generated line of a finalized entry is frozen (INSERT/UPDATE/DELETE).
            -- Calculation writes lines only while the entry is pending/calculated.
            CREATE OR REPLACE FUNCTION raqmi_payroll_entry_line_immutable_when_finalized()
            RETURNS trigger AS $$
            DECLARE
                parent_status text;
            BEGIN
                SELECT status INTO parent_status FROM payroll_entries
                    WHERE id = COALESCE(NEW.payroll_entry_id, OLD.payroll_entry_id);
                IF parent_status = 'finalized' THEN
                    RAISE EXCEPTION 'payroll_entry_line of finalized entry % is immutable',
                        COALESCE(NEW.payroll_entry_id, OLD.payroll_entry_id)
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            -- A manual adjustment in a CLOSED period is frozen (INSERT/UPDATE/DELETE):
            -- a closed period's authoritative inputs can never change afterward.
            CREATE OR REPLACE FUNCTION raqmi_payroll_adjustment_immutable_when_closed()
            RETURNS trigger AS $$
            DECLARE
                period_status text;
            BEGIN
                SELECT p.status INTO period_status
                    FROM payroll_runs r
                    JOIN payroll_periods p ON p.id = r.payroll_period_id
                    WHERE r.id = COALESCE(NEW.payroll_run_id, OLD.payroll_run_id);
                IF period_status = 'closed' THEN
                    RAISE EXCEPTION 'payroll_adjustment of a closed period is immutable'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            -- A payroll_period that is closed is frozen. The close transition passes
            -- because OLD.status is still 'open' at that moment.
            CREATE OR REPLACE FUNCTION raqmi_payroll_period_immutable_when_closed()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'closed' THEN
                    RAISE EXCEPTION 'payroll_period % is closed and immutable', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_payroll_run_immutable ON payroll_runs;
            CREATE TRIGGER trg_payroll_run_immutable
                BEFORE UPDATE OR DELETE ON payroll_runs
                FOR EACH ROW EXECUTE FUNCTION raqmi_payroll_run_immutable_when_finalized();

            DROP TRIGGER IF EXISTS trg_payroll_entry_immutable ON payroll_entries;
            CREATE TRIGGER trg_payroll_entry_immutable
                BEFORE UPDATE OR DELETE ON payroll_entries
                FOR EACH ROW EXECUTE FUNCTION raqmi_payroll_entry_immutable_when_finalized();

            DROP TRIGGER IF EXISTS trg_payroll_entry_line_immutable ON payroll_entry_lines;
            CREATE TRIGGER trg_payroll_entry_line_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON payroll_entry_lines
                FOR EACH ROW EXECUTE FUNCTION raqmi_payroll_entry_line_immutable_when_finalized();

            DROP TRIGGER IF EXISTS trg_payroll_adjustment_immutable ON payroll_adjustments;
            CREATE TRIGGER trg_payroll_adjustment_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON payroll_adjustments
                FOR EACH ROW EXECUTE FUNCTION raqmi_payroll_adjustment_immutable_when_closed();

            DROP TRIGGER IF EXISTS trg_payroll_period_immutable ON payroll_periods;
            CREATE TRIGGER trg_payroll_period_immutable
                BEFORE UPDATE OR DELETE ON payroll_periods
                FOR EACH ROW EXECUTE FUNCTION raqmi_payroll_period_immutable_when_closed();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_payroll_run_immutable ON payroll_runs;
            DROP TRIGGER IF EXISTS trg_payroll_entry_immutable ON payroll_entries;
            DROP TRIGGER IF EXISTS trg_payroll_entry_line_immutable ON payroll_entry_lines;
            DROP TRIGGER IF EXISTS trg_payroll_adjustment_immutable ON payroll_adjustments;
            DROP TRIGGER IF EXISTS trg_payroll_period_immutable ON payroll_periods;
            DROP FUNCTION IF EXISTS raqmi_payroll_run_immutable_when_finalized();
            DROP FUNCTION IF EXISTS raqmi_payroll_entry_immutable_when_finalized();
            DROP FUNCTION IF EXISTS raqmi_payroll_entry_line_immutable_when_finalized();
            DROP FUNCTION IF EXISTS raqmi_payroll_adjustment_immutable_when_closed();
            DROP FUNCTION IF EXISTS raqmi_payroll_period_immutable_when_closed();
        SQL);
    }
};
