<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close the finalized-line FK-reassignment bypass (Phase 2B hardening, blocker B12).
 * The published payroll_entry_lines immutability trigger resolved the parent entry
 * with COALESCE(NEW.payroll_entry_id, OLD.payroll_entry_id), which on UPDATE sees
 * only the NEW parent — so a finalized entry's line could be moved to a
 * non-finalized entry, mutating finalized history. Rebuild the trigger to check the
 * OLD parent (on UPDATE/DELETE) AND the NEW parent (on INSERT/UPDATE): a line may
 * not leave a finalized entry, be inserted/moved into one, and finalized→finalized
 * is likewise blocked. Ordinary pre-finalization line writes remain allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_payroll_entry_line_immutable_when_finalized()
            RETURNS trigger AS $$
            DECLARE
                old_status text;
                new_status text;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    SELECT status INTO old_status FROM payroll_entries WHERE id = OLD.payroll_entry_id;
                    IF old_status = 'finalized' THEN
                        RAISE EXCEPTION 'payroll_entry_line of finalized entry % is immutable', OLD.payroll_entry_id
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    SELECT status INTO new_status FROM payroll_entries WHERE id = NEW.payroll_entry_id;
                    IF new_status = 'finalized' THEN
                        RAISE EXCEPTION 'payroll_entry_line of finalized entry % is immutable', NEW.payroll_entry_id
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Restore the prior (COALESCE-based) trigger function.
        DB::unprepared(<<<'SQL'
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
        SQL);
    }
};
