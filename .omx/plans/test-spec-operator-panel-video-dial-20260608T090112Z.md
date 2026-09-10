# Test Spec: Operator panel video dial

## Static Checks

- `content.php` contains both:
  - `callExtensionDirect(...)`
  - `callExtensionVideo(...)`
- `index.php` contains a shared direct-call helper and `quickDial(videoEnabled)`.
- `jssip-client.js` uses `options.video === true` to set:
  - `mediaConstraints.video`
  - `rtcOfferConstraints.offerToReceiveVideo`
- `jssip-client.js` has video cleanup for session end and hangup.

## Manual Browser Checks

1. Register the operator SIP account.
2. Click the existing phone button on an online extension.
   - Expected: audio-only call starts.
   - Browser should not request camera permission.
3. Hang up.
4. Click the new video button on an online extension.
   - Expected: browser requests camera permission if not already granted.
   - Expected: call starts with video offer.
   - Expected: floating video window appears after remote video arrives.
5. Hang up.
   - Expected: floating video window disappears and camera LED turns off.

## Runtime Checks

During a video call, verify on FreeSWITCH:

```sh
fs_cli -x 'show calls'
fs_cli -x 'uuid_dump UUID' | egrep -i 'm=video|h264|vp8|rtp_use_video|switch_[rm]_sdp'
```

Expected:

- SDP contains `m=video`.
- Negotiated video codec is present when the endpoint and PBX codec prefs allow it.

## Regression Checks

- Group call, emergency call, conference invite, and incoming auto-answer remain audio-only unless their existing code passes `video: true`.
- Existing direct-call status bar and hangup function still work.
