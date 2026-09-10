# Test Spec: FusionPBX 5.5 Webroot Deploy Regression

## Local checks
- Verify local repository version and recent changes.
- Verify tar package scope and absence/presence of runtime config files.
- Verify login bootstrap files have no local modifications that explain login failure.
- Verify FreeSWITCH template files that match remote runtime issues.

## Server checks
- Check `/var/www/fusionpbx` version, git metadata if present, and file timestamps.
- Check `/etc/fusionpbx/config.conf` or `/etc/fusionpbx/config.php` exists and is readable by PHP.
- Check PostgreSQL connectivity and FusionPBX schema version/default settings state.
- Check nginx and PHP-FPM logs for login request failures.
- Check current `/etc/freeswitch/autoload_configs/post_load_modules.conf.xml`, `post_load_switch.conf.xml`, and `modules.conf.xml`.
- Restart/observe FreeSWITCH only after config backup, then validate with `systemctl status`, `pgrep`, and `fs_cli -x status`.

## Pass criteria
- Login failure root cause is identified with a concrete log/config/schema signal.
- FreeSWITCH either stays running for at least 90 seconds and responds to ESL, or the next blocking failure is isolated to a concrete module/config.
