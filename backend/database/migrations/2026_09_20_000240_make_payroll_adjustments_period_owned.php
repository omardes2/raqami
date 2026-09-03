<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-key payroll_adjustments from RUN ownership to PERIOD ownership (Phase 2B
 * hardening, blockers B1/B2). A manual adjustment is an authoritative payroll INPUT
 * for the (period, employee), not for a single run: if a run is cancelled and a
 * replacement run is created for the SAME open period, the replacement must consume
 * the exact same adjustment rows (same ids) with no copy. Additive, data-preserving:
 * backfill payroll_period_id from the owning run before dropping payroll_run_id.
 *
 * Also: add source_payroll_entry_id (traceability for a manual future-period
 * correction — never an auto retro delta); rename label -> employee_visible_label
 * and reason -> internal_reason (private); tighten the amount CHECK to > 0; and
 * rebuild the closed-period immutability trigger to key off payroll_period_id and
 * check BOTH the OLD and NEW parent period (no COALESCE that only sees one side),
 * while forbidding any period/employee reassignment of an existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pg = DB::getDriverName() === 'pgsql';

        // The published closed-period trigger reads payroll_run_id and would block the
        // backfill of finalized/closed-period rows. Drop it first; a period-based
        // trigger is recreated at the end.
        if ($pg) {
            DB::statement('DROP TRIGGER IF EXISTS trg_payroll_adjustment_immutable ON payroll_adjustments');
        }

        DB::statement('ALTER TABLE payroll_adjustments ADD COLUMN payroll_period_id char(26) NULL');
        DB::statement('ALTER TABLE payroll_adjustments ADD COLUMN source_payroll_entry_id char(26) NULL');

        // Backfill period ownership from the owning run.
        DB::statement('UPDATE payroll_adjustments a SET payroll_period_id = r.payroll_period_id FROM payroll_runs r WHERE r.id = a.payroll_run_id');

        // Fail closed if any row could not be backfilled (never silently drop data).
        $orphans = (int) (DB::selectOne('SELECT COUNT(*) AS c FROM payroll_adjustments WHERE payroll_period_id IS NULL')->c ?? 0);
        if ($orphans > 0) {
            throw new RuntimeException("payroll_adjustments backfill left {$orphans} rows without a period");
        }

        // Rename the columns to their final, privacy-distinct names.
        DB::statement('ALTER TABLE payroll_adjustments RENAME COLUMN label TO employee_visible_label');
        DB::statement('ALTER TABLE payroll_adjustments RENAME COLUMN reason TO internal_reason');

        // Foreign keys, index, and NOT NULL for the new ownership column.
        DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_period_fk FOREIGN KEY (payroll_period_id) REFERENCES payroll_periods (id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_source_entry_fk FOREIGN KEY (source_payroll_entry_id) REFERENCES payroll_entries (id) ON DELETE SET NULL');
        DB::statement('CREATE INDEX payroll_adjustments_period_employee_idx ON payroll_adjustments (tenant_id, payroll_period_id, employee_id)');
        DB::statement('ALTER TABLE payroll_adjustments ALTER COLUMN payroll_period_id SET NOT NULL');

        // Drop the old run ownership (index, FK, column).
        DB::statement('DROP INDEX IF EXISTS payroll_adjustments_tenant_id_payroll_run_id_employee_id_index');
        DB::statement('ALTER TABLE payroll_adjustments DROP CONSTRAINT IF EXISTS payroll_adjustments_payroll_run_id_foreign');
        DB::statement('ALTER TABLE payroll_adjustments DROP COLUMN payroll_run_id');

        if ($pg) {
            // Amount must be strictly positive at the DB, not merely non-negative.
            DB::statement('ALTER TABLE payroll_adjustments DROP CONSTRAINT IF EXISTS payroll_adjustments_amount_nonneg_chk');
            DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_amount_positive_chk CHECK (amount_minor > 0)');

            // Period-based closed immutability + no period/employee reassignment. Checks
            // BOTH the OLD and the NEW parent period, so a closed-period adjustment can
            // neither be moved out to an open period nor an open one moved into a closed
            // period, and an existing row's period/employee can never be reassigned.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION raqmi_payroll_adjustment_immutable_when_closed()
                RETURNS trigger AS $$
                DECLARE
                    old_status text;
                    new_status text;
                BEGIN
                    IF TG_OP = 'UPDATE' THEN
                        IF NEW.payroll_period_id IS DISTINCT FROM OLD.payroll_period_id
                            OR NEW.employee_id IS DISTINCT FROM OLD.employee_id THEN
                            RAISE EXCEPTION 'payroll_adjustment period/employee is immutable'
                                USING ERRCODE = 'restrict_violation';
                        END IF;
                    END IF;

                    IF TG_OP IN ('UPDATE', 'DELETE') THEN
                        SELECT status INTO old_status FROM payroll_periods WHERE id = OLD.payroll_period_id;
                        IF old_status = 'closed' THEN
                            RAISE EXCEPTION 'payroll_adjustment of a closed period is immutable'
                                USING ERRCODE = 'restrict_violation';
                        END IF;
                    END IF;

                    IF TG_OP IN ('INSERT', 'UPDATE') THEN
                        SELECT status INTO new_status FROM payroll_periods WHERE id = NEW.payroll_period_id;
                        IF new_status = 'closed' THEN
                            RAISE EXCEPTION 'payroll_adjustment of a closed period is immutable'
                                USING ERRCODE = 'restrict_violation';
                        END IF;
                    END IF;

                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_payroll_adjustment_immutable
                    BEFORE INSERT OR UPDATE OR DELETE ON payroll_adjustments
                    FOR EACH ROW EXECUTE FUNCTION raqmi_payroll_adjustment_immutable_when_closed();
            SQL);
        }
    }

    public function down(): void
    {
        $pg = DB::getDriverName() === 'pgsql';

        if ($pg) {
            DB::statement('DROP TRIGGER IF EXISTS trg_payroll_adjustment_immutable ON payroll_adjustments');
        }

        // Restore run ownership (best-effort backfill from the period's latest run) so
        // the migration can be re-applied. Down is intentionally not hardened.
        DB::statement('ALTER TABLE payroll_adjustments ADD COLUMN payroll_run_id char(26) NULL');
        DB::statement('UPDATE payroll_adjustments a SET payroll_run_id = (SELECT r.id FROM payroll_runs r WHERE r.payroll_period_id = a.payroll_period_id ORDER BY r.created_at DESC LIMIT 1)');

        DB::statement('ALTER TABLE payroll_adjustments RENAME COLUMN employee_visible_label TO label');
        DB::statement('ALTER TABLE payroll_adjustments RENAME COLUMN internal_reason TO reason');

        DB::statement('ALTER TABLE payroll_adjustments DROP CONSTRAINT IF EXISTS payroll_adjustments_period_fk');
        DB::statement('ALTER TABLE payroll_adjustments DROP CONSTRAINT IF EXISTS payroll_adjustments_source_entry_fk');
        DB::statement('DROP INDEX IF EXISTS payroll_adjustments_period_employee_idx');
        DB::statement('ALTER TABLE payroll_adjustments DROP COLUMN payroll_period_id');
        DB::statement('ALTER TABLE payroll_adjustments DROP COLUMN source_payroll_entry_id');

        DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_payroll_run_id_foreign FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE');
        DB::statement('CREATE INDEX payroll_adjustments_tenant_id_payroll_run_id_employee_id_index ON payroll_adjustments (tenant_id, payroll_run_id, employee_id)');

        if ($pg) {
            DB::statement('ALTER TABLE payroll_adjustments DROP CONSTRAINT IF EXISTS payroll_adjustments_amount_positive_chk');
            DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_amount_nonneg_chk CHECK (amount_minor >= 0)');

            DB::unprepared(<<<'SQL'
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

                CREATE TRIGGER trg_payroll_adjustment_immutable
                    BEFORE INSERT OR UPDATE OR DELETE ON payroll_adjustments
                    FOR EACH ROW EXECUTE FUNCTION raqmi_payroll_adjustment_immutable_when_closed();
            SQL);
        }
    }
};
