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

- **New add-on: ActiveSync logs in the admin** (`activesync-logs`). A page
  right after *User logs* shows the Z-Push logs (`z-push.log`,
  `z-push-error.log`), newest first, with filters by level, account and text.
  Poste.io never rotates these logs; the page sets their rotation (on/off,
  each log every day/week/month, 1 to 12 old copies; defaults: weekly, 4), with
  the same design as the External Mailboxes rotation: the admin saves the
  settings, a root script writes the logrotate rule at 00:00 and at every
  start. No Clear button: the logs belong to Z-Push (`www-data`) and stay so.
  Independent of the other add-ons; nothing to do by hand on an existing
  installation (see [docs](docs/activesync-logs.md)).

- **New add-on: Backup** (`backup`). Encrypted, scheduled backups of the
  server, set up and watched from the admin (*Mailserver backup*, super administrators
  only): the configuration (accounts, domains, settings, certificates, DKIM
  keys, calendars and address books, webmail settings, spam filter training,
  the other add-ons' settings) and all the mail or chosen mailboxes, to one or
  more destinations at once: SMB/Samba, SFTP (server key confirmed in the
  admin, password or a key pair made by the admin), S3, a volume mounted
  by the host (NFS, a second disk), or restic's **REST server in append-only
  mode** (HTTPS, its certificate confirmed in the admin, and confirmed again
  when the server presents a new one): the mail server can
  only add there, so someone who takes it over cannot delete the backups; the
  old ones are removed on the server's side (documented). Made with restic (deduplicated,
  compressed, checked) and rclone from the image's Debian packages; the
  databases are copied consistently while in use. Jobs every hour, day, week
  or month in a time zone of their own, with keep rules per job; a **Test**
  of each destination before saving (reachability, empty folder or existing
  repository, free space, a write); live progress, the logs of every run,
  the space on each destination (yellow and red from limits set in the admin, 80% and 90% by default); a
  scheduled **verification** of each destination (restic check and a part of
  the data read again, a different part each time: every Sunday, a quarter,
  by default; *Verify now*); **statistics** on the dashboard (space used on
  each destination and on the server, data added and duration of the runs,
  per day over 30 days, 90 days or a year); logs kept for a number of days; pages and
  messages in English and Czech. The
  encryption key is made at the first start and downloaded from the admin.
  The engine runs as `mail`, never as root, and validates the settings again
  before using them. **Snapshots and restore** in the admin: the snapshots
  of each destination, a mailbox restored into a new folder of the same or
  another mailbox (named *Restored <time>* or as you choose, at the top or
  inside an existing folder): the whole mailbox, some of its folders, the
  messages of a filter (dates received, sender, subject), or single messages
  chosen from a list of the snapshot's messages (sender, subject, date;
  searchable; up to 1,000 at a time; the list is kept by the engine and is
  part of the backup of the configuration); nothing already
  there is changed, and it is refused if `/data` has no room for it; the backups of a job removed (also of a
  deleted job), and the commands to rebuild the whole server filled in for a
  snapshot. The backups can also be read from another machine with only the
  key file, and a whole server is rebuilt on a new installation: both
  procedures are run by the tests as written in the [docs](docs/backup.md).

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

### Fixed
- **Upstream fixes: the spam learning scripts no longer leave every message in
  `/tmp`.** Poste.io's `spam` / `ham` scripts copy the message to a temporary
  file for `rspamc` and never remove it, so each message moved into or out of
  Junk stayed in the container's `/tmp` until the container was recreated. A
  third fix removes the file when the script ends, also when it is stopped
  ([upstream fixes](docs/upstream-fixes.md#3-every-learned-message-stays-in-tmp)).

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
