# PRD: Operator panel video dial

## Objective

Add a video direct-call option to Basic Operator Panel while preserving all existing audio direct-call behavior.

## User-Facing Behavior

- Extension cards show the existing phone button and a new video button.
- Phone button starts an audio-only call.
- Video button starts an audio+video call.
- If the operator is not SIP registered, the existing registration warning is shown.
- On video calls, a compact floating video window appears when a remote video track arrives.
- Local camera preview is shown in the same floating window when a video local stream is available.
- Hangup removes the video window and stops related media tracks.

## Implementation Plan

1. Update extension-card rendering in `resources/content.php`.
   - Keep existing `.btn-call-direct` button.
   - Add `.btn-call-video` button using FontAwesome `fa-video`.
   - Call `callExtensionVideo(extension)`.

2. Update direct-call JavaScript in `index.php`.
   - Introduce `startDirectExtensionCall(extension, videoEnabled)`.
   - Make `callExtensionDirect(extension)` call the shared function with `false`.
   - Add `callExtensionVideo(extension)` calling the shared function with `true`.
   - Update `quickDial(videoEnabled)` to pass the requested video flag and keep old callers audio-only.

3. Update `jssip-client.js`.
   - Add helpers for media constraints and video-capable stream creation.
   - Honor `options.video` in `makeCall()`.
   - Keep existing audio-only mic stream reuse for audio-only calls.
   - Add remote video rendering and local preview rendering helpers.
   - Clean video elements and local preview streams when calls end.

4. Update `dispatcher.css`.
   - Add direct video button variant.
   - Add compact floating video call window styles.
   - Add responsive sizing for smaller screens.

## Acceptance Criteria

- Audio button still calls `makeCall(..., { audio: true, video: false })`.
- Video button calls `makeCall(..., { audio: true, video: true })`.
- Outbound JsSIP `mediaConstraints.video` and `offerToReceiveVideo` are true for video calls.
- Camera is not requested for audio-only calls.
- Video DOM elements are removed on hangup/session end.
- No syntax errors in touched PHP/JS files.
