# Ralph Evidence: Operator panel video dial

## Changed Files

- `app/basic_operator_panel/resources/content.php`
- `app/basic_operator_panel/index.php`
- `app/basic_operator_panel/resources/jssip-client.js`
- `app/basic_operator_panel/resources/dispatcher.css`

## Implementation Evidence

- Extension cards now render the existing audio call button and a new `btn-call-video` button wired to `callExtensionVideo(extension)`.
- Direct extension calls now route through `startDirectExtensionCall(extension, videoEnabled)`.
- Audio direct calls pass `{ audio: true, video: false }` through the shared helper.
- Video direct calls pass `{ audio: true, video: true }`.
- `quickDial(videoEnabled)` remains backward compatible: omitted or false values stay audio-only.
- JsSIP outbound calls now derive `mediaConstraints.video` and `rtcOfferConstraints.offerToReceiveVideo` from `options.video === true`.
- Audio-only outbound calls continue using `getOrCreateMicStream()` and do not request camera media.
- Video outbound calls request audio plus constrained camera media and show a local preview.
- Remote video tracks are attached to a compact floating video window.
- Video elements are removed through call end, call failure, hangup, reject-by-id, hangup-by-id, and release-all cleanup paths.
- `getResourceReport()` now includes `activeVideo`.

## Verification

- `php -l app\basic_operator_panel\index.php`
  - Passed: `No syntax errors detected in app\basic_operator_panel\index.php`
  - Note: local PHP emitted `Failed loading E:\php\ext\php_xdebug.dll`.
- `php -l app\basic_operator_panel\resources\content.php`
  - Passed: `No syntax errors detected in app\basic_operator_panel\resources\content.php`
  - Note: local PHP emitted `Failed loading E:\php\ext\php_xdebug.dll`.
- `node --check app\basic_operator_panel\resources\jssip-client.js`
  - Passed with exit code 0.
- `git diff --check`
  - Passed with only repository line-ending warnings.
- Targeted `rg` checks confirmed:
  - `callExtensionVideo`
  - `quickDial(videoEnabled)`
  - `video: videoEnabled`
  - `offerToReceiveVideo: videoEnabled`
  - `activeVideo`
  - `_removeAllVideoElements`
  - `btn-call-video`
  - `dispatcher-video-call-window`

## Deslop Pass

- Scope was limited to the four changed application files.
- Fallback-like findings were limited to existing cleanup-boundary `catch (e) {}` patterns and newly added media/DOM cleanup guards that match the local file style.
- No masking fallback, dead code, or safe behavior-preserving deletion was found after the failed-call stream cleanup was added.
- Post-deslop verification repeated:
  - `php -l app\basic_operator_panel\index.php` passed.
  - `php -l app\basic_operator_panel\resources\content.php` passed.
  - `node --check app\basic_operator_panel\resources\jssip-client.js` passed.
  - `git diff --check` passed with only line-ending warnings.

## Not Tested

- Live browser SIP video call was not executed in this session.
- FreeSWITCH SDP verification (`m=video`, negotiated codec) was not executed in this session.
