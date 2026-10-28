# Security policy

## Reporting a vulnerability

Report vulnerabilities privately through GitHub: open the repository's
[Security tab](https://github.com/michaljonik/nextcloud-sightline/security) and choose
"Report a vulnerability". Please do not open a public issue for them.

Only the latest release receives security fixes.

## What counts

Sightline's security rests on two rules (see [docs/architecture.md](docs/architecture.md)):
every search result passes Nextcloud's own permission check for the searching user, and
`sightline-embed` holds no Nextcloud credential. Reports in these areas are the most useful:

- a search result, thumbnail or link for a photo the user cannot open in Nextcloud;
- reading, writing or deleting vectors in `sightline-embed` without its token;
- the token leaking, for example into logs, the settings page or API responses;
- anything that lets `sightline-embed` read files or act in Nextcloud.

`sightline-embed` is meant to run on an internal network. Exposing it to the internet is not
a supported setup, but a way around the token check is still a vulnerability.
