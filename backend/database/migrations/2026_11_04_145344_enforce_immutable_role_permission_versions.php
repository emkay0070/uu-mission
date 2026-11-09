<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_published_role_permission_version_change() RETURNS trigger AS $$
            BEGIN
                IF OLD.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Published role permission versions are immutable';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared('CREATE TRIGGER role_permission_versions_immutable BEFORE UPDATE OR DELETE ON role_permission_versions FOR EACH ROW EXECUTE FUNCTION reject_published_role_permission_version_change()');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_published_role_permission_item_change() RETURNS trigger AS $$
            DECLARE
                old_published boolean := false;
                new_published boolean := false;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    SELECT published_at IS NOT NULL INTO old_published FROM role_permission_versions WHERE id = OLD.role_permission_version_id;
                END IF;
                IF TG_OP <> 'DELETE' THEN
                    SELECT published_at IS NOT NULL INTO new_published FROM role_permission_versions WHERE id = NEW.role_permission_version_id;
                END IF;
                IF old_published OR new_published THEN
                    RAISE EXCEPTION 'Published role permission sets are immutable';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared('CREATE TRIGGER role_permission_version_items_immutable BEFORE INSERT OR UPDATE OR DELETE ON role_permission_version_items FOR EACH ROW EXECUTE FUNCTION reject_published_role_permission_item_change()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS role_permission_version_items_immutable ON role_permission_version_items');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_published_role_permission_item_change()');
            DB::unprepared('DROP TRIGGER IF EXISTS role_permission_versions_immutable ON role_permission_versions');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_published_role_permission_version_change()');
        }
    }
};
