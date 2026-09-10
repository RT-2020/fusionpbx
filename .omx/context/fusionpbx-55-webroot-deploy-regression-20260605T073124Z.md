# FusionPBX 5.5 Webroot Deploy Regression

## Task statement
Diagnose why deploying the local FusionPBX repository to the server by only overwriting `/var/www/fusionpbx` worked on FusionPBX 5.4 but now correlates with FreeSWITCH failing to stay up and the web admin login failing.

## Desired outcome
Identify whether the local repository or deployment strategy is incompatible with the installed server state, then define a safe recovery path for FreeSWITCH and FusionPBX login without unnecessary rewrites.

## Known facts / evidence
- Local repository version history shows current upstream at `5.5.7`.
- The user states the server was deployed by official script, then local repository contents were packed and extracted over `/var/www/fusionpbx`.
- The local tar archive contains web repository paths such as `app/`, `core/`, `resources/`, and includes `app/switch/resources/conf/autoload_configs/*.xml`.
- The local tar archive does not appear to contain absolute `/etc/freeswitch`, `/etc/fusionpbx/config.conf`, or `resources/config.php`.
- Local `app/switch/resources/conf/autoload_configs/post_load_modules.conf.xml` loads `mod_av`.
- Local `app/switch/resources/conf/autoload_configs/post_load_switch.conf.xml` sets `initial-event-threads` to `8`.
- Local `app/switch/resources/conf/autoload_configs/modules.conf.xml` loads several optional/missing modules, including `mod_bv`, multiple `mod_say_*`, `mod_flite`, and `mod_tts_commandline`.
- Remote logs previously showed FreeSWITCH repeatedly reaches `FreeSWITCH Started` / `System Ready` and then exits cleanly.
- Remote `/etc/freeswitch` had config matching several local template values, including `post_load_modules.conf.xml` loading `mod_av` and `post_load_switch.conf.xml` using `initial-event-threads=8`.

## Constraints
- Prefer direct SSH execution for commands that must run on the server.
- Avoid asking the user to paste long commands.
- Do not make destructive server changes without a backup and a clear reason.
- Keep local repository analysis read-only unless a planned code change is specifically selected.

## Unknowns / open questions
- Exact server FusionPBX database schema/version before webroot overwrite.
- Whether `core/upgrade` or app defaults were run after replacing 5.4-era code with 5.5.7-era code.
- Exact PHP/nginx error behind the login failure.
- Whether the interrupted remote `mod_av` isolation command partially completed.
- Whether FreeSWITCH is failing because of `mod_av`, module/template mismatch, or another runtime config.

## Likely touchpoints
- `resources/classes/config.php`
- `resources/require.php`
- `resources/login.php`
- `core/upgrade/`
- `app/switch/resources/conf/autoload_configs/`
- Server `/etc/fusionpbx/config.conf`
- Server `/etc/freeswitch/autoload_configs/`
- Server nginx/php-fpm logs
