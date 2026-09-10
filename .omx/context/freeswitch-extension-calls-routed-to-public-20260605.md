# FreeSWITCH extension calls routed to public context

Date: 2026-06-05

## Symptom

- After registration was fixed, `1004` calling `1003` failed.
- FreeSWITCH log showed:
  - `Processing 1004 <1004>->1003 in context public`
  - route hit `public->not-found`
  - `respond(404 Not Found)`
  - hangup cause `UNALLOCATED_NUMBER`

## Root Cause

The call was arriving on `sofia/external`, whose context is `public`.

Extension-to-extension calls must arrive on the internal SIP profile and use the domain context `192.168.2.2`. Because `/etc/freeswitch/vars.xml` was missing key variables, `external_sip_port` did not expand to `5080`, so the external profile was bound to `5060`. Softphones registered and called through `sofia/external`, causing extension calls to be treated as inbound public-route calls.

Additional issue:

- `/etc/freeswitch/sip_profiles/internal.xml`
- `/etc/freeswitch/sip_profiles/internal-ipv6.xml`

had `context=public`, which is wrong for internal extensions in this deployment.

## Fix Applied

Backups created on the server:

- `/root/vars.xml.before-profile-routing-20260605175538`
- `/root/internal.xml.before-profile-routing-20260605175538`
- `/root/internal-ipv6.xml.before-profile-routing-20260605175538`
- `/root/sip_profiles_before_profile_routing_20260605175538.sql`

Updated `/etc/freeswitch/vars.xml` with:

```xml
<X-PRE-PROCESS cmd="set" data="domain=192.168.2.2" />
<X-PRE-PROCESS cmd="set" data="default_password=1234" />
<X-PRE-PROCESS cmd="set" data="internal_sip_port=5060" />
<X-PRE-PROCESS cmd="set" data="internal_tls_port=5061" />
<X-PRE-PROCESS cmd="set" data="external_sip_port=5080" />
<X-PRE-PROCESS cmd="set" data="external_tls_port=5081" />
```

Updated internal SIP profile context:

- `internal`: `context=192.168.2.2`
- `internal-ipv6`: `context=192.168.2.2`

Updated FusionPBX DB profile settings for `internal` and `internal-ipv6`:

- `context=192.168.2.2`

Restarted FreeSWITCH.

## Verification

Final Sofia state:

- `internal`: `sip:mod_sofia@192.168.2.2:5060`, `Context=192.168.2.2`
- `internal-ipv6`: `[::1]:5060`
- `external`: `sip:mod_sofia@192.168.2.2:5080`, `Context=public`
- `external-ipv6`: `[::1]:5080`
- alias `192.168.2.2 -> internal`

Global vars:

- `domain=192.168.2.2`
- `internal_sip_port=5060`
- `external_sip_port=5080`
- `default_password=1234`

Directory/contact checks:

- `user_exists id 1003 192.168.2.2` -> `true`
- `sofia_contact */1003@192.168.2.2` -> `sofia/internal/...`
- `sofia_contact */1004@192.168.2.2` -> `error/user_not_registered`

The remaining action is to re-register the `1004` softphone so it appears under `sofia/internal`.
