# FusionPBX extension creation recovered by schema upgrade

Date: 2026-06-05

## Symptom

- FreeSWITCH was already restored to a stable running state.
- FusionPBX web login was restored.
- Duplicate domains were disabled from the UI, leaving one visible `192.168.2.2` domain.
- Running `Upgrade -> App Defaults`, `Menu Defaults`, and `Group Permission Defaults` did not restore extension creation.
- Creating a new extension from the UI still failed, and `v_extensions` remained empty.

## Resolution

The user then ran `Upgrade -> Schema`.

After the database schema upgrade completed, creating extensions worked again.

## Root Cause

Most likely root cause: the deployment strategy copied a newer local FusionPBX web tree over `/var/www/fusionpbx` without running the matching database schema migrations.

FusionPBX 5.5 code expected database structure/columns/indexes that the installed database had not yet been upgraded to. Default settings, menu defaults, and group permission defaults do not apply schema migrations, so they could not fix this class of failure.

## Operational Note

For future deployments that overwrite `/var/www/fusionpbx` from a packaged local repository:

1. Back up PostgreSQL before deployment.
2. Replace the web tree.
3. Run FusionPBX upgrade steps in the UI, including:
   - Schema
   - App Defaults
   - Menu Defaults
   - Group Permission Defaults
4. Verify login, FreeSWITCH status, and one extension create/delete smoke test.

Do not hard-delete the disabled duplicate domains/admin users until extension creation and login are stable and a fresh database backup exists.
