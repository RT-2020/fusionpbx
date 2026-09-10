# PRD: Enable Linphone extension video calls through FusionPBX

## Objective

Make extension-to-extension calls between Linphone Android clients `1003` and `1004` negotiate video on the FusionPBX/FreeSWITCH deployment at `192.168.2.2`.

## Diagnosis

The primary likely server-side issue is codec preference configuration, not missing codec modules. `show codecs` proves video modules/codecs are loaded, but the FusionPBX default codec variables observed in the repo are audio-only. Internal Sofia profiles reference `$${global_codec_prefs}`, so a profile with audio-only prefs will not offer/accept video codecs even when the modules are present.

The Android symptom `通话正在录制` is not evidence that the video button is wired to recording. Local Linphone source shows video and recording are separate controls. That toast is produced by the recording path, including automatic call recording if enabled.

## Required Runtime Checks

Run on the PBX host before applying changes:

```sh
fs_cli -x 'eval $${global_codec_prefs}'
fs_cli -x 'eval $${outbound_codec_prefs}'
fs_cli -x 'sofia status profile internal' | egrep -i 'codec|context|url|sip-ip|rtp-ip'
fs_cli -x 'show registrations' | egrep '1003|1004'
fs_cli -x 'user_data 1003@192.168.2.2 var absolute_codec_string'
fs_cli -x 'user_data 1004@192.168.2.2 var absolute_codec_string'
fs_cli -x 'user_data 1003@192.168.2.2 var bypass_media'
fs_cli -x 'user_data 1004@192.168.2.2 var bypass_media'
```

Expected problem confirmation:

- `global_codec_prefs` or profile codec lines lack `H264` and `VP8`.
- Any `absolute_codec_string` on `1003` or `1004` is blank or includes video. If it is audio-only, it must be cleared or updated.

## Fix Plan

1. In FusionPBX, set codec variables under Advanced -> Variables -> Codecs:
   - `global_codec_prefs`: `G722,PCMU,PCMA,H264,VP8`
   - `outbound_codec_prefs`: `PCMU,PCMA,H264,VP8`

2. If using direct server edits instead of the UI, update both runtime XML and FusionPBX DB after backing up:

```sh
stamp="$(date +%Y%m%d%H%M%S)"
sudo cp -a /etc/freeswitch/vars.xml "/root/vars.xml.before-video-codecs-$stamp"
sudo -u postgres pg_dump fusionpbx > "/root/fusionpbx_before_video_codecs_$stamp.sql"

sudo perl -0pi -e 's/data="global_codec_prefs=[^"]*"/data="global_codec_prefs=G722,PCMU,PCMA,H264,VP8"/' /etc/freeswitch/vars.xml
sudo perl -0pi -e 's/data="outbound_codec_prefs=[^"]*"/data="outbound_codec_prefs=PCMU,PCMA,H264,VP8"/' /etc/freeswitch/vars.xml

sudo -u postgres psql -d fusionpbx -c "update v_vars set var_value = 'G722,PCMU,PCMA,H264,VP8', var_enabled = 'true' where var_name = 'global_codec_prefs';"
sudo -u postgres psql -d fusionpbx -c "update v_vars set var_value = 'PCMU,PCMA,H264,VP8', var_enabled = 'true' where var_name = 'outbound_codec_prefs';"
```

3. Verify internal SIP profile settings:
   - `inbound-codec-prefs` should be `$${global_codec_prefs}` or explicitly include `H264,VP8`.
   - `outbound-codec-prefs` should be `$${global_codec_prefs}` or explicitly include `H264,VP8`.
   - `context` should remain `192.168.2.2` from the previous routing fix.

4. Verify extensions `1003` and `1004`:
   - Clear `Absolute Codec String`, or include video: `G722,PCMU,PCMA,H264,VP8`.
   - Leave `SIP Bypass Media` blank initially. Add `proxy-media` only if packet capture proves direct endpoint RTP/NAT is blocking video.

5. Apply runtime:

```sh
fs_cli -x 'reloadxml'
fs_cli -x 'sofia profile internal restart reloadxml'
fs_cli -x 'sofia profile internal-ipv6 restart reloadxml'
```

6. Re-register both Linphone clients after the profile restart.

7. On each Linphone client:
   - Settings -> Calls -> Enable Video: ON.
   - Settings -> Calls -> Auto Record Calls: OFF while testing.
   - Settings -> Advanced -> Video Codecs: enable `H264` and `VP8`.
   - Android app permission: Camera allowed.

## Acceptance Criteria

- `fs_cli -x 'eval $${global_codec_prefs}'` includes `H264,VP8`.
- `sofia status profile internal` reflects video-capable codec prefs after profile restart.
- `1003` and `1004` are registered on `sofia/internal`.
- During a call, `uuid_dump` shows SDP with `m=video` and negotiated `H264` or `VP8`.
- Linphone video button changes call video direction; the recording toast does not appear unless recording or auto-record is enabled.

## Blocker

Remote verification and runtime changes cannot be completed from this session until SSH credentials or `FS_PASSWORD` are available.
