# FusionPBX intranet SSL certificate execution - 2026-06-08

## Task

Execute `docs/FusionPBX内网SSL证书配置指南.md` to resolve browser HTTPS and FreeSWITCH WSS certificate warnings for the intranet FusionPBX host.

## Target

- Server: `192.168.2.2`
- HTTPS: nginx `443`
- FreeSWITCH WSS: `7443`
- FreeSWITCH WS: `5066`

## Actions

- Generated a local FusionPBX CA on the Debian server:
  - `/etc/ssl/fusionpbx-local-ca/fusionpbx-local-rootCA.pem`
  - `/etc/ssl/fusionpbx-local-ca/fusionpbx-local-rootCA.crt`
- Generated a server certificate for `192.168.2.2` with SAN:
  - `IP Address:192.168.2.2`
  - `IP Address:127.0.0.1`
  - `DNS:localhost`
- Installed nginx certificate/key:
  - `/etc/ssl/private/192.168.2.2.pem`
  - `/etc/ssl/private/192.168.2.2-key.pem`
- Updated nginx FusionPBX site SSL certificate references and reloaded nginx.
- Built FreeSWITCH TLS/WSS PEM files:
  - `/etc/freeswitch/tls/agent.pem`
  - `/etc/freeswitch/tls/wss.pem`
- User enabled both `ws-binding=:5066` and `wss-binding=:7443` in FusionPBX SIP Profile `internal`.
- Ran `reloadxml` and `sofia profile internal restart` to apply the SIP profile changes.
- Saved the root CA locally:
  - `.omx/certs/fusionpbx-192.168.2.2/fusionpbx-local-rootCA.crt`
  - `.omx/certs/fusionpbx-192.168.2.2/fusionpbx-local-rootCA.pem`
- Imported the root CA into the current Windows user's trusted root certificate store.

## Backups

- nginx site config: `/root/fusionpbx-nginx-before-ssl-20260608T012012Z.conf`
- FreeSWITCH TLS directory: `/root/freeswitch-tls-before-ssl-20260608T012012Z.tar.gz`

## Verification

Server service state:

- `nginx.service`: `active/running`
- `freeswitch.service`: `active/running`

Listeners:

- `192.168.2.2:5066` listening
- `192.168.2.2:7443` listening
- `0.0.0.0:443` listening

FreeSWITCH internal profile:

- `Context`: `192.168.2.2`
- `WS-BIND-URL`: `sip:mod_sofia@192.168.2.2:5066;transport=ws`
- `WSS-BIND-URL`: `sips:mod_sofia@192.168.2.2:7443;transport=wss`

Certificate served by both `443` and `7443`:

- Subject: `CN = 192.168.2.2`
- Issuer: `CN = FusionPBX Local Root CA`
- SAN: `IP Address:192.168.2.2, IP Address:127.0.0.1, DNS:localhost`
- SHA256 fingerprint: `89:0A:4D:A5:EB:C1:20:22:A5:C7:51:EE:56:B9:EF:4E:D6:A4:FA:AF:44:84:EC:B3:4A:10:41:56:A3:53:C5:5B`

Windows client verification:

- Current-user root store contains `CN=FusionPBX Local Root CA`.
- `Invoke-WebRequest https://192.168.2.2/` returned `StatusCode=200`.
- TLS handshake to `192.168.2.2:7443` succeeded:
  - Result: `WSS_TLS_HANDSHAKE=OK`
  - Protocol: `Tls13`
  - Remote subject: `CN=192.168.2.2`
  - Remote issuer: `CN=FusionPBX Local Root CA`

## Review

Result: APPROVE / CLEAR

The server now presents the same trusted certificate on nginx HTTPS and FreeSWITCH WSS. The certificate includes the actual access IP in SAN, WSS is listening, and the current Windows user trusts the issuing CA. Remaining browser warnings, if any, should be handled by fully restarting the browser or importing the same root CA into that browser/profile if it uses a separate trust store.
