# Operator panel video dial

Timestamp: 2026-06-08T09:01:12Z

## Task Statement

Add a video-call option to the Basic Operator Panel dialing flow.

## Desired Outcome

- Existing direct audio calls from operator-panel extension cards continue to work.
- A new video direct-call action is available beside the existing direct-call action.
- Legacy `quickDial()` can initiate either an audio or video call.
- JsSIP outbound media options actually request camera/video when video is enabled.
- Remote video is visible in the operator panel during video calls and is cleaned up when calls end.

## Known Facts

- Direct extension cards are rendered in `app/basic_operator_panel/resources/content.php`.
- The existing card-level direct-call button calls `callExtensionDirect(extension)`.
- `callExtensionDirect()` in `app/basic_operator_panel/index.php` currently calls `dispatcherControl.sipClient.makeCall(..., { audio: true, video: false })`.
- `quickDial()` exists in `index.php` but no current template in this repo references `quick-dial-number`; it still should remain backward compatible.
- `app/basic_operator_panel/resources/jssip-client.js` accepts an `options` object but currently hardcodes outbound `mediaConstraints.video = false` and `offerToReceiveVideo = false`.
- Remote media handling currently creates audio elements only; there is no video rendering surface.

## Constraints

- Keep the UI consistent with the current dense operational panel.
- Do not add dependencies.
- Keep audio-only paths unchanged by default.
- Do not alter emergency/group/conference call behavior unless needed for shared media helper compatibility.

## Likely Touchpoints

- `app/basic_operator_panel/resources/content.php`
- `app/basic_operator_panel/index.php`
- `app/basic_operator_panel/resources/jssip-client.js`
- `app/basic_operator_panel/resources/dispatcher.css`
