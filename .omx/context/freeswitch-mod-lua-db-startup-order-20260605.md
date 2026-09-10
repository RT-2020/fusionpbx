# FreeSWITCH mod_lua startup order issue

Date: 2026-06-05

## Symptom

- After reboot, nginx and php-fpm were active, PostgreSQL had not been started before FreeSWITCH, and FreeSWITCH was stuck in restart/start-pre behavior.
- `fs_cli` could not connect while FreeSWITCH was still starting.
- `/var/log/freeswitch/freeswitch.log` showed repeated Lua database connection failures:
  - `Connection failed. DBH NOT Connected.`
  - `resources/functions/database/native.lua:35: assertion failed`
  - `LUA script parse/execute error`

## Cause

`mod_lua` itself was present and usable, but it was loaded very early in `/etc/freeswitch/autoload_configs/modules.conf.xml`.

FusionPBX's `/etc/freeswitch/autoload_configs/lua.conf.xml` binds:

- `app.lua xml_handler`
- bindings: `configuration,dialplan,directory,languages`

That Lua XML handler needs the FusionPBX database during FreeSWITCH startup. When FreeSWITCH started before PostgreSQL was ready, Lua attempted to connect to the database and failed. This made the service look like a `mod_lua` failure, but the deeper issue was startup order plus early Lua loading.

## Fix Applied

Backups created on the server:

- `/root/modules.conf.xml.before-mod-lua-order-20260605172817`
- `/root/freeswitch.service.before-postgresql-order-20260605172817`

Changes:

- Moved `<load module="mod_lua"/>` from line 8 to near the end of `/etc/freeswitch/autoload_configs/modules.conf.xml` before `</modules>`.
- Added systemd drop-in:
  - `/etc/systemd/system/freeswitch.service.d/postgresql-order.conf`
  - content:
    ```ini
    [Unit]
    Wants=postgresql.service
    After=postgresql.service
    ```
- Ran `systemctl daemon-reload`.
- Restarted PostgreSQL and FreeSWITCH.

## Verification

Final verified state:

- `postgresql.service`: `ActiveState=active`, `SubState=exited`
- `freeswitch.service`: `ActiveState=active`, `SubState=running`, `NRestarts=0`
- `grep -n mod_lua /etc/freeswitch/autoload_configs/modules.conf.xml`:
  - line 75: `<load module="mod_lua"/>`
- `fs_cli -x 'module_exists mod_lua'`:
  - `true`
- `fs_cli -x 'load mod_lua'`:
  - `-ERR [Module already loaded]`

## Impact If Disabled

If `mod_lua` is disabled, FreeSWITCH can still start, but FusionPBX features that depend on Lua scripts and the Lua XML handler can fail or behave incompletely. This can affect dynamic directory/dialplan/config generation and any dialplan action that calls the `lua` application.

`mod_lua` should stay enabled, but load it late and ensure PostgreSQL starts before FreeSWITCH.
