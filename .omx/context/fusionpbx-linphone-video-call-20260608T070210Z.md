# FusionPBX Linphone Android video call failure

Timestamp: 2026-06-08T07:02:10Z

## Task Statement

Linphone Android project at `D:\Project\Android\linphone-android` registers extension `1003` and calls `1004` on the FusionPBX/FreeSWITCH deployment at `192.168.2.2`. Audio registration/calling is available, but video cannot be established. Pressing the softphone video button shows the Chinese toast `通话正在录制`.

## Desired Outcome

- `1003` and `1004` can negotiate bidirectional video.
- Pressing the in-call video button toggles camera/video, not call recording.
- FreeSWITCH/FusionPBX runtime configuration remains consistent after `reloadxml`, Sofia profile restart, and later FusionPBX saves.

## Known Facts And Evidence

- User-provided `show codecs` confirms FreeSWITCH has video codecs loaded:
  - H.264 passthrough via `mod_h26x`
  - H264 Video via `mod_av`
  - VP8/VP9 via `CORE_VPX_MODULE`
- Local FusionPBX default `app/switch/resources/conf/vars.xml` has audio-only codec variables:
  - `global_codec_prefs=G7221@32000h,G7221@16000h,G722,PCMU,PCMA`
  - `outbound_codec_prefs=PCMU,PCMA`
- Local SIP profile templates load codec prefs from those variables:
  - `internal.xml.noload`: inbound and outbound codec prefs use `$${global_codec_prefs}`
  - `external.xml.noload`: inbound uses `$${global_codec_prefs}`, outbound uses `$${outbound_codec_prefs}`
- FusionPBX `app/vars/app_defaults.php` imports `app/switch/resources/conf/vars.xml` into `v_vars` on initial setup, then `save_var_xml()` writes runtime XML.
- Extension directory generation can override codecs per extension:
  - `absolute_codec_string` is emitted as an extension variable if set.
  - `sip_bypass_media` can emit `bypass_media`, `bypass_media_after_bridge`, or `proxy_media`.
- Linphone provisioning template `resources/templates/provision/linphone/default/{$address}.xml` enables video capture/display and AVPF.
- Local Linphone Android UI inspection found:
  - `call_actions_generic.xml` video button calls `viewModel.toggleVideo()`.
  - `call_actions_bottom_sheet.xml` record button calls `viewModel.toggleRecording()`.
  - `CurrentCallViewModel.toggleVideo()` updates call params with `isVideoEnabled = true`.
  - `CurrentCallViewModel.toggleRecording()` is the path that shows `call_is_being_recorded`.
  - `CoreContext` auto-starts call recording on `StreamsRunning` if `app.auto_start_call_record` is enabled.
- Remote execution is currently blocked:
  - `FS_PASSWORD` is missing in the local environment.
  - `ssh -F NUL -o BatchMode=yes debian@192.168.2.2 ...` fails with `Permission denied (publickey,password)`.

## Constraints

- Do not make destructive changes to the running PBX without credentials and backup.
- Updating only `/etc/freeswitch/vars.xml` is not sufficient if FusionPBX DB still has old `v_vars` values; later UI saves can overwrite runtime XML.
- Updating only the FusionPBX DB is not sufficient unless runtime XML is regenerated or `/etc/freeswitch/vars.xml` is updated as well.
- `reloadxml` alone may not reload already-started Sofia profile codec preferences; restart/rescan the affected Sofia profile after changing codec prefs.

## Unknowns

- Current running values of `$${global_codec_prefs}` and `$${outbound_codec_prefs}` on `192.168.2.2`.
- Current `v_vars` values in the FusionPBX database.
- Current `1003` / `1004` extension `absolute_codec_string` and `sip_bypass_media` values.
- Current Linphone app setting for `app.auto_start_call_record`.
- Whether the active SIP re-INVITE/SDP contains `m=video` and a mutually accepted `H264` or `VP8` codec.

## Likely Touchpoints

- FusionPBX UI: Advanced -> Variables -> Codecs
- FusionPBX UI: Accounts -> Extensions -> 1003 / 1004 -> Advanced
- Runtime files: `/etc/freeswitch/vars.xml`, `/etc/freeswitch/sip_profiles/internal.xml`
- FreeSWITCH commands: `reloadxml`, `sofia profile internal restart reloadxml`, `show registrations`, `uuid_dump`
- Linphone Android settings: Enable Video, Video Codecs, Auto Record Calls, Camera permission
