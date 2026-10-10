# poste.io-addons

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tested with Poste.io 2.5.17](https://img.shields.io/badge/tested%20with-Poste.io%202.5.17%20(Pro%20%26%20open%20source)-2b9348.svg)](docs/installation.md)

Add-ons for the [Poste.io](https://poste.io) mail server, built as a Docker
image on top of the official one:

- **External Mailboxes** — fetch mail from POP3/IMAP accounts hosted elsewhere
  and deliver it into Poste.io on a schedule, managed from the admin.
- **Dark theme for the admin** — automatic (follows the system) with a switch
  in the top bar.
- **Two upstream fixes** that make "move to Junk" actually teach rspamd.

English and Czech, like the Poste.io admin. Dockerfiles for the Pro and the
open source edition: in production on **Poste.io 2.5.17 PRO #230**; the open
source image (`analogic/poste.io:2.5.17`) was built and started in a test
container (routes, menu, translations, theme, crontab and fixes checked).

> Not affiliated with or endorsed by Poste.io / Analogic. "Poste.io" is a
> trademark of its owners; this project only contains add-ons and no Poste.io
> code.

![External mailboxes, light](docs/screenshots/external-mailboxes-list.png)

## Features

### External Mailboxes

![Edit form, dark theme](docs/screenshots/external-mailbox-edit-dark.png)

- POP3, POP3 over SSL, IMAP and IMAP over SSL, via
  [getmail6](https://github.com/getmail6/getmail6), every 5–60 minutes.
- Delivery to any local mailbox **or redirect**: deliver an external
  `support@` box to a redirect and everyone in it gets the mail.
- IMAP folder picker (**Load folders from server**) and optional cleanup of
  the remote Trash/Spam folders with a retention period.
- **Test connection** from the form and from every row of the list.
- getmail options in the form: read all/new, delete, delete after N days,
  limits per run, delete messages over a size, port, timeout.
- Logs page with the getmail and cron logs and a status view.
- Hardened: getmail runs as `mail`, never root; configuration files `0600`;
  TLS certificates verified; passwords never sent back to the browser; explicit
  CSRF tokens; strict validation of every value written to getmail.

→ [docs/external-mailboxes.md](docs/external-mailboxes.md)

![Logs page](docs/screenshots/external-mailbox-logs.png)

### Dark theme

![Dashboard, dark theme](docs/screenshots/dashboard-dark.png)

- Follows the system setting, or force light/dark from the top bar (remembered
  by the browser).
- No white flash on load; the login page follows the system too.
- Charts are recoloured and redrawn when the theme changes.
- The light theme is untouched: every rule applies to the dark theme only.

→ [docs/dark-theme.md](docs/dark-theme.md)

### Upstream fixes

On 2.5.17 moving a message to Junk does not train rspamd: the learning scripts
are world-writable (Dovecot refuses to run them) and they learn into a Bayes
bucket that scanning never reads. The Dockerfiles fix both, and the build fails
if Poste.io changes the scripts, so the fixes never silently stop applying.

→ [docs/upstream-fixes.md](docs/upstream-fixes.md)

## Quick start

```bash
git clone https://github.com/atsanna/poste.io-addons.git
cd poste.io-addons

# Pro (requires docker login to the Poste.io registry)
docker build -f Dockerfile-pro --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
# or open source
docker build -f Dockerfile     --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
```

Run the image as you run the official one (see
[`docker-compose.example.yml`](docker-compose.example.yml)). On an **existing**
installation, read [docs/installation.md](docs/installation.md#3-existing-installations-the-data-volume)
first: the `/data` volume hides the folders the image prepares.

## Repository layout

```
admin/                 files copied into /opt/admin (controllers, templates, dark-theme.css)
external-mailboxes/    translations, crontab of the mail user, cron.sh (the getmail runner)
dark-theme/            translations and the script that hooks the theme into base.html.twig
docs/                  documentation and screenshots
Dockerfile             open source edition (analogic/poste.io)
Dockerfile-pro         Pro edition (poste.io/mailserver)
```

## Documentation

- [Installation, upgrades and checks](docs/installation.md)
- [External Mailboxes](docs/external-mailboxes.md)
- [Dark theme](docs/dark-theme.md)
- [Upstream fixes](docs/upstream-fixes.md)
- [Security policy](SECURITY.md) · [Changelog](CHANGELOG.md)

Screenshots use demo data (`example.com`, documentation IP ranges).

## License

[MIT](LICENSE) © 2026 Antonio Sanna. The license covers the files of this
repository only; Poste.io itself is licensed by its owners.
