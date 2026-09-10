# FusionPBX softphone registration only worked for 1002

Date: 2026-06-05

## Symptom

- Extensions `1000` through `1010` existed in FusionPBX.
- All had password `1234`.
- Softphone registration appeared to work only for `1002`.
- FreeSWITCH logs for `1003@192.168.2.2` initially showed:
  - `operator does not exist: text = boolean`
  - query condition: `e.enabled = true`
- After fixing that SQL type issue, logs changed to:
  - `Can't find user [1003@192.168.2.2]`

## Root Causes

There were three interacting issues.

1. `v_extensions.enabled` is `text`, but FusionPBX 5.5 Lua scripts queried it as boolean:
   - wrong: `e.enabled = true`
   - correct for this schema: `e.enabled = 'true'`

2. The database still had duplicate disabled domains with the same name `192.168.2.2`.
   The main Lua directory handler resolved:
   - `SELECT domain_uuid FROM v_domains WHERE domain_name = :domain_name`

   Without filtering `domain_enabled`, it could select disabled domain UUID `eabaac15-64d3-4ccd-b5c4-468e906577b6` instead of the active domain:
   - active: `12872859-bd04-4c9f-9328-dfdd656a4f2f`

3. `/var/cache/fusionpbx` had a stale/generated cache entry only for:
   - `debian.directory.1002@192.168.2.2`

   This made `1002` look special even though the other extensions were valid in the DB.

## Fix Applied

Patched local repo and server runtime/web scripts.

Changed `v_extensions.enabled` conditions to text comparisons in:

- `app/switch/resources/scripts/directory.lua`
- `app/switch/resources/scripts/app/xml_handler/resources/scripts/directory/directory.lua`
- `app/switch/resources/scripts/app/xml_handler/resources/scripts/directory/action/reverse-auth-lookup.lua`
- `app/switch/resources/scripts/app/xml_handler/resources/scripts/configuration/acl.conf.lua`
- `app/switch/resources/scripts/app/xml_handler/resources/scripts/directory/action/group_call.lua`
- `app/switch/resources/scripts/app/feature_event/resources/functions/feature_event_notify.lua`

Added enabled-domain filtering for domain UUID resolution in:

- `app/switch/resources/scripts/app/xml_handler/resources/scripts/directory/directory.lua`
- `app/switch/resources/scripts/resources/functions/settings.lua`
- `app/switch/resources/scripts/app/xml_handler/resources/scripts/languages/languages.lua`
- `app/switch/resources/scripts/app/xml_handler/resources/scripts/directory/action/group_call.lua`

Server-side runtime files under `/usr/share/freeswitch/scripts/...` and web source files under `/var/www/fusionpbx/app/switch/resources/scripts/...` were patched as well.

FusionPBX cache was cleared:

- `/var/cache/fusionpbx/*`

FreeSWITCH XML was reloaded:

- `fs_cli -x reloadxml`

## Verification

Final FreeSWITCH lookups:

- `user_data 1000@192.168.2.2 param password` -> `1234`
- `user_data 1002@192.168.2.2 param password` -> `1234`
- `user_data 1003@192.168.2.2 param password` -> `1234`
- `user_data 1010@192.168.2.2 param password` -> `1234`

User context lookup:

- `1000`, `1002`, `1003`, `1010` -> `192.168.2.2`

Service state:

- `freeswitch.service`: `ActiveState=active`, `SubState=running`, `NRestarts=0`

## Remaining Note

The duplicate disabled domains still exist. The runtime path now filters enabled domains, but a later explicit cleanup should delete the disabled duplicates only after a fresh DB backup and user approval.
