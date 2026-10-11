# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **External Mailboxes: getmail errors in the admin.** `cron.sh` writes what
  getmail reports (errors and warnings) to `/data/log/getmail/errors.log`,
  with the time and the mailbox, shown as the new **Getmail Errors** log type
  (with its own **Clear**). The default getmail view shows a red line on top
  when there are such lines from the last 24 hours. Until now these lines
  went only to `/data/log/mail.log`, which the admin cannot read: a
  configuration getmail rejected, for example, was visible nowhere in the
  admin. **Existing installations**: `cron.sh` lives on the `/data` volume,
  copy the new one there (see
  [installation](docs/installation.md#3-existing-installations-the-data-volume)).
- **External Mailboxes: fetched mail is checked by rspamd.** Until now it was
  not scanned at all: getmail delivers through Haraka's local submission
  socket, where the rspamd plugin skips local connections. A getmail filter
  (`getmail-rspamd`) now scans every fetched message before delivery: same
  `X-Rspamd-*` headers as SMTP mail, spam subject prefix from Poste.io's own
  threshold (filed into Junk by the mailbox's sieve rule), Bayes per domain of
  the recipient. If rspamd does not answer, the message is delivered unchanged
  and the reason goes to the Getmail Errors log. The connection-based checks do
  not apply (no SMTP client): see [docs](docs/external-mailboxes.md).
  **Existing mailboxes** get the filter when they are saved again from the
  admin (the password field can stay empty; see
  [installation](docs/installation.md#3-existing-installations-the-data-volume)).

- **External Mailboxes: log rotation, configurable.** `getmail.log` and
  `errors.log` were never rotated. They now are, by Poste.io's nightly
  logrotate, with settings in the logs page (**Rotation**): on/off, getmail
  log every day/week/month, errors above 512 KB to 10 MB, 1 to 12 old copies
  kept compressed (defaults: weekly, 1 MB, 4). The admin saves the settings in
  `/data/var/getmail/log-rotation.ini`; a root script writes the logrotate rule
  from them every night at 00:00, accepting only the allowed values (see
  [docs](docs/external-mailboxes.md#log-rotation)).

### Changed
- **External Mailboxes: "Read all" only with "Delete message".** With "Read
  all" on and the messages left on the server, getmail warns at every run that
  "all messages will be retrieved each time getmail is run" (a warning that so
  far went only to `mail.log`). The form now switches "Read all" off while
  "Delete message" is off, the admin refuses the combination, and a new
  mailbox starts with "Read all" off (it was on). The comment written above `read_all` in the
  configuration file said the opposite of what the option does: fixed.
  Existing mailboxes keep their settings; one saved again from the admin with
  this combination gets "Read all" off.

## [1.1.0] - 2026-10-11

Tested on Poste.io 2.5.17 (Pro #230 in production; open source image built and
started in a test container).

### Added
- **Calendars and contacts over ActiveSync** (`activesync-dav`): Z-Push goes
  from mail only to mail, calendars, tasks and contacts of Poste.io's
  CalDAV/CardDAV server; enabled per mailbox from the admin; the default
  calendar is the one the server names (RFC 6638); calendars shared read-only
  appear as additional calendars; mail keeps working if the DAV server is
  switched off or broken. Existing installations: devices must resync once.
- **Choosing the add-ons**: one Dockerfile section per add-on, files in one
  folder per add-on, and `select-addons.sh` to build only the ones you want.
- **End-to-end tests** (`tests/`) and a GitHub Actions workflow: the open
  source image is built with all the add-ons and with each one alone, and
  tested against a real Poste.io container (admin driven like a browser, real
  IMAP and ActiveSync requests). Badge in the README.

### Fixed
- **External Mailboxes**: an IMAP account with a single folder (the default,
  `INBOX`) was written as `mailboxes = ("INBOX")`, which getmail rejects as
  "not a tuple": such accounts never fetched anything. Now `("INBOX",)`.
  Accounts with two or more folders were not affected. Found by the new
  tests; existing single-folder accounts start working when they are saved
  again from the admin.
- Z-Push issues worked around in the ActiveSync backends: an event refused by
  the DAV server (read-only calendar) was confirmed to the phone as saved; a
  contact added by a user without an address book crashed Z-Push (HTTP 500,
  mail included).

### Changed
- Repository layout: the admin files moved from `admin/` to
  `external-mailboxes/admin/` and `dark-theme/admin/`.

## [1.0.0] - 2026-10-10

First public release, tested on Poste.io 2.5.17 (Pro #230 in production; open
source image built and started in a test container).

### Added
- **External Mailboxes**: fetch POP3/IMAP (SSL) accounts with getmail6 on a
  schedule; delivery to mailboxes or redirects; IMAP folder picker; remote
  Trash/Spam cleanup with retention; *Test connection* from the form and from
  the list; logs page with status view; English and Czech translations.
- **Dark theme** for the admin: automatic (`prefers-color-scheme`) plus a
  switch in the top bar, charts recoloured live.
- **Upstream fixes** for spam learning from Junk (script permissions, Bayes
  bucket), with build-time checks.

### Security
- getmail runs as `mail`, never root; configuration files `0600`, folders
  `0750`.
- TLS certificate and host name verification for SSL accounts.
- Passwords are never sent back to the browser; the saved password is reused
  by the test buttons only for the same server, port, protocol and username.
- Explicit CSRF tokens on every form and AJAX endpoint.
- Strict validation of every value written to the getmail configuration.
