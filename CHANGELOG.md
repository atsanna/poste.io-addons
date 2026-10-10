# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

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
