# Ralph Evidence: FusionPBX Linphone video call

## Result

Implementation on the running PBX is blocked by missing SSH credentials, but the diagnosis and remediation plan are grounded in local FusionPBX and Linphone Android source evidence.

## Evidence Collected

- FreeSWITCH codec modules are present based on the user's `show codecs` output.
- Local FusionPBX active default codec variables are audio-only in `app/switch/resources/conf/vars.xml`.
- Internal SIP profile templates depend on `$${global_codec_prefs}` for codec negotiation.
- FusionPBX extension XML generation can override codecs with `absolute_codec_string`.
- Linphone Android source separates video toggling and call recording:
  - `call_actions_generic.xml` -> `viewModel.toggleVideo()`
  - `call_actions_bottom_sheet.xml` -> `viewModel.toggleRecording()`
  - `CoreContext` can auto-start recording when `automaticallyStartCallRecording` is enabled.

## Attempted Remote Verification

- Checked local environment for `FS_PASSWORD`: missing.
- Attempted non-interactive SSH with local config bypass:

```text
ssh -F NUL -o BatchMode=yes -o ConnectTimeout=5 debian@192.168.2.2 ...
```

Result:

```text
Permission denied (publickey,password).
```

## Handoff

Use:

- `.omx/plans/prd-fusionpbx-linphone-video-call-20260608T070210Z.md`
- `.omx/plans/test-spec-fusionpbx-linphone-video-call-20260608T070210Z.md`

to apply and verify the runtime fix once credentials are available.
