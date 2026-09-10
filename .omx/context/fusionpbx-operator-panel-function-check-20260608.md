# FusionPBX operator panel function check - 2026-06-08

## Scope

Checked operator panel capabilities after confirming normal extension calls:

- Group call
- Broadcast/all call
- Emergency sound-light alarm service path

The alarm check intentionally did not trigger a real alarm command because the physical alarm device is not connected.

## Findings

### Base services

- `nginx.service`: active/running
- `freeswitch.service`: active/running
- `postgresql.service`: active/exited, normal PostgreSQL umbrella state
- FreeSWITCH modules:
  - `mod_conference`: true
  - `mod_esf`: true
  - `mod_event_socket`: true

### Current registrations

Registered extensions during the check:

- `1000`
- `1001`
- `1002`
- `1003`
- `1010`

All-call uses online/available extensions from the operator panel flow, so currently it should only target these online extensions unless more phones register.

### Group call / all call implementation

Code entry points:

- `app/basic_operator_panel/index.php`
- `app/basic_operator_panel/resources/dispatcher-control.js`
- `app/basic_operator_panel/dispatcher_conference_api.php`

Behavior:

- Group call opens a selection modal and can manually select extensions.
- Group filtering uses `call_group`, but group call does not require `call_group` if extensions are selected manually.
- All-call reuses the group-call conference bridge flow and targets all online extensions returned by the panel.
- Backend creates an inline FreeSWITCH conference and invites participants through ESL `originate`.

Validation:

- `dispatcher_conference_api.php`: PHP syntax OK
- `dispatcher_api.php`: PHP syntax OK
- `exec.php`: PHP syntax OK
- `mod_conference`: loaded
- `conference list`: returned `+OK No active conferences`
- `show channels count`: returned `0 total`
- `/etc/freeswitch/autoload_configs/conference.conf.xml`: exists

Assessment:

- Group call and all-call infrastructure is available.
- A real functional test still requires selecting online extensions from the browser panel and observing whether invited phones auto-answer or ring according to endpoint behavior.

### Alarm service

Code entry points:

- `app/basic_operator_panel/dispatcher_api.php`
- `app/basic_operator_panel/resources/service/emergency_alarm.php`
- `app/basic_operator_panel/resources/classes/emergency_alarm_service.php`
- `app/basic_operator_panel/resources/classes/emergency_alarm_device.php`

Original problem:

- `emergency_alarm.service` was repeatedly exiting with `status=255/EXCEPTION`.
- Foreground diagnostic showed:
  - `PHP Fatal error: Access level to emergency_alarm_service::debug() must be protected (as in class service) or weaker`

Fix applied:

- Changed `emergency_alarm_service::debug()` from private to protected.
- Changed `emergency_alarm_service::info()` from private to protected.
- Added compatible type signature:
  - `protected function debug(string $message = ''): void`
  - `protected function info(string $message = ''): void`

Files changed:

- Local repo: `app/basic_operator_panel/resources/classes/emergency_alarm_service.php`
- Server runtime: `/var/www/fusionpbx/app/basic_operator_panel/resources/classes/emergency_alarm_service.php`

Server backups:

- `/root/emergency_alarm_service-before-visibility-20260608T031924Z.php`
- `/root/emergency_alarm_service-before-visibility-fix2-20260608T033045Z.php`

Post-fix validation:

- `php -l emergency_alarm_service.php`: no syntax errors
- `emergency_alarm.service`: active/running
- Foreground duplicate-start smoke test returns `[ERROR] Service already running`, confirming the service is alive
- Runtime table now reports the configured endpoint and expected degraded state because the physical device is absent:
  - `service_state=degraded`
  - `device_state=error`
  - `device_endpoint=192.168.50.1:9003`
  - `last_error=Alarm recovery [startup] failed: Unable to connect to alarm device: Connection timed out (110)`
- TCP check to `192.168.50.1:9003`: failed, expected while the physical alarm device is not connected

Assessment:

- The code/service path is now viable.
- With no physical device connected, the correct expected result is a degraded/error device state instead of service crash.
- When the original alarm device at `192.168.50.1:9003` is connected and reachable, this path should be able to proceed to device command testing.

## Remaining risks

- All-call only reaches currently online extensions.
- Phone auto-answer behavior depends on endpoint/provisioning support and SIP headers.
- Physical sound-light alarm behavior cannot be fully verified until the alarm device is connected at the configured endpoint.
