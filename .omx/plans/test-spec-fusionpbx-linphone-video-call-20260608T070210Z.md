# Test Spec: FusionPBX Linphone video call

## Static Evidence Already Collected

- `app/switch/resources/conf/vars.xml` defaults are audio-only for active codec variables.
- `app/switch/resources/conf/sip_profiles/internal.xml.noload` uses `$${global_codec_prefs}` for inbound and outbound codec prefs.
- Extension XML generation emits `absolute_codec_string` and media bypass/proxy variables if configured.
- Linphone Android video button and recording button are separate code paths.

## Server Precheck

Run on `192.168.2.2`:

```sh
fs_cli -x 'status'
fs_cli -x 'show codecs' | egrep 'H264|VP8|VP9|G.722|G.711'
fs_cli -x 'eval $${global_codec_prefs}'
fs_cli -x 'eval $${outbound_codec_prefs}'
fs_cli -x 'sofia status profile internal' | egrep -i 'codec|context|url|sip-ip|rtp-ip'
fs_cli -x 'show registrations' | egrep '1003|1004'
```

Pass conditions:

- FreeSWITCH is running.
- `show codecs` includes at least `H264` and `VP8`.
- Codec prefs include `H264,VP8`.
- `1003` and `1004` are registered on `sofia/internal`.

## Extension Override Check

```sh
fs_cli -x 'user_data 1003@192.168.2.2 var absolute_codec_string'
fs_cli -x 'user_data 1004@192.168.2.2 var absolute_codec_string'
fs_cli -x 'user_data 1003@192.168.2.2 var bypass_media'
fs_cli -x 'user_data 1004@192.168.2.2 var bypass_media'
fs_cli -x 'user_data 1003@192.168.2.2 var proxy_media'
fs_cli -x 'user_data 1004@192.168.2.2 var proxy_media'
```

Pass conditions:

- `absolute_codec_string` is empty or includes `H264,VP8`.
- Media bypass/proxy is empty during the first test unless specifically needed for NAT.

## Apply Verification

After updating codec prefs:

```sh
fs_cli -x 'reloadxml'
fs_cli -x 'sofia profile internal restart reloadxml'
fs_cli -x 'show registrations' | egrep '1003|1004'
fs_cli -x 'eval $${global_codec_prefs}'
fs_cli -x 'sofia status profile internal' | egrep -i 'codec|context|url|sip-ip|rtp-ip'
```

Pass conditions:

- Both clients re-register on `sofia/internal`.
- Profile codec output includes `H264` and `VP8`.

## Live Call Verification

1. Start call from `1003` to `1004`.
2. Accept on `1004`.
3. Press the in-call video button on one Linphone client.
4. Collect active call UUIDs:

```sh
fs_cli -x 'show calls'
```

5. Dump each UUID:

```sh
fs_cli -x 'uuid_dump UUID' | egrep -i 'rtp_use_codec|video|h264|vp8|switch_[rm]_sdp|endpoint_disposition|media'
```

Pass conditions:

- SDP contains `m=video`.
- Negotiated video codec is `H264` or `VP8`.
- Linphone local preview appears on the sender and remote video appears on the receiver.
- No `通话正在录制` toast appears unless Settings -> Calls -> Auto Record Calls is enabled or the record button is pressed.

## Failure Triage

- If `m=video` is absent from caller SDP: fix Linphone video setting, camera permission, or use the video-call action before/during the call.
- If `m=video` is present in caller SDP but absent in FreeSWITCH answer: fix profile codec prefs and extension `absolute_codec_string`.
- If video negotiates but no picture flows: inspect RTP/NAT/firewall; test `proxy-media` or packet capture UDP RTP ports.
- If recording toast appears without pressing record: disable Linphone Settings -> Calls -> Auto Record Calls and retest.
