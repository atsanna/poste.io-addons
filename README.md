# poste.io-addons

[![tests](https://github.com/atsanna/poste.io-addons/actions/workflows/tests.yml/badge.svg)](docs/testing.md)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tested with Poste.io 2.5.17](https://img.shields.io/badge/tested%20with-Poste.io%202.5.17%20(Pro%20%26%20open%20source)-2b9348.svg)](docs/installation.md)

Add-ons for the [Poste.io](https://poste.io) mail server, built as a Docker
image on top of the official one:

- **External Mailboxes** — fetch mail from POP3/IMAP accounts hosted elsewhere
  and deliver it into Poste.io on a schedule, managed from the admin.
- **Dark theme for the admin** — automatic (follows the system) with a switch
  in the top bar.
- **Calendars and contacts over ActiveSync** — the phone's Exchange account
  gets the calendars and contacts of Poste.io's DAV server, not only the mail.
- **Two upstream fixes** that make "move to Junk" actually teach rspamd.

Take all of them or only the ones you want: each add-on is one section of the
Dockerfile ([choosing the add-ons](docs/choosing-addons.md)).

English and Czech, like the Poste.io admin. Dockerfiles for the Pro and the
open source edition: in production on **Poste.io 2.5.17 PRO #230**; the open
source image is built and tested end to end on every push, with all the
add-ons and with each one alone ([tests](docs/testing.md)).

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
  limits per run, delete messages over a size, port, timeout. "Read all" only
  together with "Delete message" (otherwise everything left on the server
  would be fetched again at every run).
- **Spam check**: every fetched message goes through rspamd before delivery,
  like mail received over SMTP (headers, spam into Junk, Bayes); without it
  fetched mail is not scanned by Poste.io at all.
- Logs page with the getmail log, the **getmail errors** (with a red line on
  top of the getmail view when there are recent ones), the cron log and a
  status view.
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

### Calendars and contacts over ActiveSync

- One Exchange/ActiveSync account on the phone: mail, calendars, tasks and
  contacts, both ways, from Poste.io's built-in CalDAV/CardDAV server.
- Enabled per mailbox in the admin, as before (*Create calendar*, *Create
  address book*); a mailbox without them keeps mail only.
- A colleague's calendar shared **read-only** appears as an additional
  calendar: see whether someone is busy before booking them.
- If the DAV server is switched off or broken, mail keeps working.
- Fixes three Z-Push issues found while testing (an event refused by the
  server confirmed as saved, a crash on contacts without an address book,
  ActiveSync down with the DAV server).

→ [docs/activesync-dav.md](docs/activesync-dav.md) (existing installations:
devices must resync once)

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

# only some add-ons, e.g. the dark theme and calendars/contacts
sh select-addons.sh Dockerfile-pro dark-theme activesync-dav > Dockerfile.custom
docker build -f Dockerfile.custom --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
```

Run the image as you run the official one (see
[`docker-compose.example.yml`](docker-compose.example.yml)). On an **existing**
installation, read [docs/installation.md](docs/installation.md#3-existing-installations-the-data-volume)
first: the `/data` volume hides the folders the image prepares, and phones
using ActiveSync must resync once.

## Repository layout

```
external-mailboxes/    admin pages (admin/), translations, crontab of the mail user, cron.sh (the getmail runner), getmail-rspamd (the spam check filter)
dark-theme/            admin files (admin/), translations, the script that hooks the theme into base.html.twig
activesync-dav/        Z-Push backends and the script that configures Z-Push
tests/                 end-to-end tests (run.sh, the admin driven like a browser, an ActiveSync client)
docs/                  documentation and screenshots
Dockerfile             open source edition (analogic/poste.io), one section per add-on
Dockerfile-pro         Pro edition (poste.io/mailserver), one section per add-on
select-addons.sh       prints a Dockerfile with only the add-ons you name
```

## Documentation

- [Installation, upgrades and checks](docs/installation.md)
- [Choosing the add-ons](docs/choosing-addons.md)
- [External Mailboxes](docs/external-mailboxes.md)
- [Dark theme](docs/dark-theme.md)
- [Calendars and contacts over ActiveSync](docs/activesync-dav.md)
- [Upstream fixes](docs/upstream-fixes.md)
- [Tests](docs/testing.md)
- [Security policy](SECURITY.md) · [Changelog](CHANGELOG.md)

Screenshots use demo data (`example.com`, documentation IP ranges).

## License

[MIT](LICENSE) © 2026 Antonio Sanna. The license covers the files of this
repository only; Poste.io itself is licensed by its owners.
