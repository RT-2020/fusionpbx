# FusionPBX 5.5 Webroot Deploy Regression - Ralph Evidence

## Outcome

Server `192.168.2.2` was repaired to a stable FreeSWITCH/FusionPBX state after a webroot-only FusionPBX 5.5 deployment caused generated FreeSWITCH configuration drift.

## Confirmed Root Causes

1. `/etc/freeswitch/vars.xml` was missing SIP port variables:
   - `external_sip_port`
   - `external_tls_port`
   - `internal_sip_port`
   - `internal_tls_port`

   Without these, both internal and external Sofia profiles resolved to port `5060`, causing startup profile conflicts.

2. `/etc/freeswitch/sip_profiles/internal.xml` and `external.xml` contained over-escaped variables such as:
   - `$$$$$${external_rtp_ip}`
   - `$$$$$${external_sip_ip}`
   - `$$$$$${internal_ssl_enable}`

   FreeSWITCH expanded these to values like `$$$$192.168.2.2`, producing invalid Sofia bind URLs.

3. `mod_lua` was stable when loaded after startup, but caused clean shutdown when loaded early in `modules.conf.xml`.

   Moving `mod_lua` to the end of `/etc/freeswitch/autoload_configs/modules.conf.xml` kept Lua available and avoided the startup clean-exit loop.

## Server Changes Applied

Backups created before edits:

- `/root/internal.xml.dollarfix-20260605155013.bak`
- `/root/external.xml.dollarfix-20260605155013.bak`
- `/root/vars.xml.portfix-20260605155545.bak`
- `/root/modules.conf.xml.restore-20260605160725.bak`
- `/root/post_load_modules.conf.xml.restore-20260605160725.bak`
- `/root/modules.conf.xml.lua-late-test-20260605163703.bak`

Config changes:

- Fixed over-escaped `$${...}` variables in:
  - `/etc/freeswitch/sip_profiles/internal.xml`
  - `/etc/freeswitch/sip_profiles/external.xml`
- Added standard SIP port variables to `/etc/freeswitch/vars.xml`:
  - `external_sip_port=5080`
  - `external_tls_port=5081`
  - `internal_sip_port=5060`
  - `internal_tls_port=5061`
- Restored all installed FreeSWITCH modules that tested safe.
- Moved `mod_lua` from early module loading to the end of `modules.conf.xml`.

## Verification Evidence

Final FreeSWITCH service state:

```text
MainPID=1287923
NRestarts=0
ActiveState=active
SubState=running
```

Final FreeSWITCH CLI status:

```text
UP 0 years, 0 days, 0 hours, 3 minutes, 15 seconds
FreeSWITCH (Version 1.10.12-release git ba840f2 2026-05-04 19:18:46Z 64bit) is ready
```

Final Sofia profile state:

```text
external-ipv6  sip:mod_sofia@[::1]:5080          RUNNING
external       sip:mod_sofia@192.168.2.2:5080    RUNNING
internal-ipv6  sip:mod_sofia@[::1]:5060          RUNNING
internal       sip:mod_sofia@192.168.2.2:5060    RUNNING
```

Final Lua verification:

```text
/etc/freeswitch/autoload_configs/modules.conf.xml:80: <load module="mod_lua"/>
api,lua,mod_lua
api,luarun,mod_lua
application,lua,mod_lua
dialplan,LUA,mod_lua
```

Final web/PHP verification:

```text
curl -k https://127.0.0.1/login.php
HTTP 200 text/html; charset=UTF-8
```

Previous PHP DB smoke test:

```text
connected=yes
users=3
```

## Local Repository Findings

The local repository itself does not contain `resources/config.php` or `/etc/fusionpbx/config.conf`, so a webroot-only extract should not directly overwrite DB credentials.

Relevant local templates:

- `app/switch/resources/conf/autoload_configs/modules.conf.xml` loads `mod_lua` early by default.
- `app/switch/resources/conf/autoload_configs/post_load_modules.conf.xml` loads `mod_av`.
- `app/switch/resources/conf/autoload_configs/lua.conf.xml` enables the Lua XML handler:
  - `app.lua xml_handler`
- `app/switch/resources/conf/vars.xml` contains SSL variables but no explicit default `internal_sip_port` / `external_sip_port` values.

This supports the deployment-risk conclusion: overwriting `/var/www/fusionpbx` is not directly corrupting `/etc`, but FusionPBX 5.5 upgrade/config generation can rewrite FreeSWITCH config from newer templates and incomplete/default DB values. The deployment process needs a post-deploy config generation/validation step for `/etc/freeswitch`.

## Remaining Risks

- The nginx error log still contains old PHP fatal entries from June 4, 2026, but final local login page requests on June 5 returned 200 and PHP DB smoke tests passed.
- `resources/switch.php::save_var_xml()` still lacks a guard for failed `fopen()`. The old nginx fatal `fwrite(): Argument #1 must be resource, bool given` points to this weak error path. No local code patch was applied because local file-read escalation was declined during the turn.
- Future FusionPBX upgrade/regeneration may overwrite `/etc/freeswitch/vars.xml` and `modules.conf.xml`. The port variables and late `mod_lua` ordering should be preserved in the deployment procedure or applied after upgrade generation.
