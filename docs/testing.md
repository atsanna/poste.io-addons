# Tests

[![tests](https://github.com/atsanna/poste.io-addons/actions/workflows/tests.yml/badge.svg)](https://github.com/atsanna/poste.io-addons/actions/workflows/tests.yml)

Every push and pull request builds the **open source** image and runs
end-to-end tests against a real Poste.io container. The badge above (and the
one in the README) is the result of the last run.

The **Pro** edition is not tested here: its base image comes from the
Poste.io registry and needs a licence. The add-ons are the same files in both
editions; the Pro image is tested by hand before each release.

## What runs

[`.github/workflows/tests.yml`](../.github/workflows/tests.yml) runs six jobs
in parallel. Each builds the image from the `Dockerfile` with the Poste.io
version pinned there (`ARG POSTE_IO_VERSION`), then runs
[`tests/run.sh`](../tests/run.sh) on it:

| Job | Image |
|---|---|
| `all` | the Dockerfile as it is, every add-on |
| `external-mailboxes`, `dark-theme`, `activesync-dav`, `activesync-logs`, `upstream-fixes` | one add-on only, made with [`select-addons.sh`](choosing-addons.md) |

The single-add-on jobs prove that each add-on builds and works without the
others; the tests of the add-ons that are not in the image are reported as
`SKIP`.

## How a run works

`tests/run.sh <image>`:

1. starts a throw-away container from the image: no published ports, its own
   volume, host name `mail.example.test`; waits until the admin, ActiveSync and
   IMAP answer. The tests are written in Python (standard library only):
   Poste.io's base image has no `python3` (External Mailboxes brings it with
   getmail6), so for an image without that add-on it is installed in the
   throw-away container;
2. creates the domain `example.test` and three mailboxes (`alice`, the super
   administrator, `bob` and `carol`) with Poste.io's own console commands; the
   passwords are random, generated inside the container and never printed;
3. exports the WBXML tables from the Z-Push in the image, so that the test
   client speaks exactly Z-Push's ActiveSync encoding;
4. runs `tests/test_addons.py` and `tests/test_activesync.py` inside the
   container; every check prints `PASS` or `FAIL` with what it found;
5. removes the container and the volume. The exit code is the number of
   failed checks.

The admin is driven like a browser ([`tests/panel.py`](../tests/panel.py)):
pages are loaded, forms are read from the page (including their CSRF token),
filled in and submitted. The phone is simulated with real ActiveSync 14.0
requests ([`tests/eas.py`](../tests/eas.py)): Provision, FolderSync, Sync and
Ping, as a device sends them. Nothing is configured behind Poste.io's back.

## What is checked

**External Mailboxes** (`tests/test_addons.py`)
- menu entries, the three pages, getmail installed;
- the schedule is in the crontab of `mail` (five intervals), nothing for root;
  getmail folders `0750` owned by `mail`;
- a form posted without its CSRF token is refused (HTTP 403, nothing saved);
- a mailbox created from the admin is written as a getmail file `0600` owned by
  `mail`, with the IMAP folder list as a valid Python tuple;
- the password is never sent back to the browser;
- end to end: a message put in bob's mailbox is fetched by `cron.sh` (run as
  `mail`, like cron) over IMAP and delivered to alice; the logs page shows it;
- rspamd on fetched mail: the getmail file has the `[filter1]` section; the
  fetched message arrives with `X-Rspamd-Score` (its report is printed); a
  fetched GTUBE test message arrives in Junk with the `*****SPAM*****`
  subject;
- "Read all" only with "Delete message": off for a new mailbox; with "Delete
  message" off it is refused (HTTP 400, nothing saved), with it on it is
  saved; the comment above `read_all` in the generated file says what it does;
- log rotation: the default rule (weekly, 1 MB, 4 copies) and its nightly
  cron entry; the settings in the logs page; saving without the CSRF token is
  refused; saved settings (`0640`, owned by `mail`) are applied by the root
  script; a value not offered by the form is refused; a settings file changed
  behind the admin gives the defaults and runs nothing; rotation switched off
  leaves no getmail log in the rule; a forced rotation keeps
  `getmail.log.1`, recreates `getmail.log` and getmail writes to it; the page
  shows the old copies;
- getmail errors: after a good run `errors.log` is empty (`0640`, owned by
  `mail`) and the getmail view has no error line; a mailbox with a wrong
  password is added from the admin and run: the login error is in
  `errors.log` with its mailbox, on top of the **Getmail Errors** view as an
  error, and summarised in a red line on top of the getmail view; after the
  mailbox is deleted from the admin, **Clear** without its CSRF token is
  refused and with it empties the file, and the red line is gone.

**ActiveSync logs** (`tests/test_addons.py`)
- the menu entry right after *User logs*; the settings folder made by the
  start script on the fresh volume (`0750`, owned by `mail`); the default rule
  (both logs weekly, 4 copies, rotated as `www-data`, no `copytruncate`) and its
  nightly cron entry;
- a real Z-Push error: an ActiveSync request with a wrong password is on top
  of the page as an error, with its account; the Z-Push logs stay `www-data`
  `0644`;
- with known lines in Z-Push's format: newest first, a message written over
  two lines shown as one, the log text escaped (no HTML from the log), the
  level filters, the account menu and filter (an account not in the menu is
  ignored), the case-insensitive search, a search text that is not run by
  the shell, the errors log;
- no Clear form; the rotation settings: refused without the CSRF token,
  saved `0640` owned by `mail`, applied by the root script, a value not offered
  by the form refused, a settings file changed behind the admin gives the
  defaults and runs nothing, rotation switched off leaves no Z-Push log in the
  rule; a forced rotation keeps `z-push.log.1`, recreates `z-push.log` `0644`
  owned by `www-data`, Z-Push writes to it at the next request, and the page
  shows the old copies;
- `lint:yaml` and `lint:twig` pass.

**Dark theme** (`tests/test_addons.py`)
- the two `include` lines are in `base.html.twig` once each;
- the stylesheet is served as `text/css` and contains the dark rules;
- the login page loads the stylesheet and the theme script in `<head>`;
- the switch is in the top bar; translations (en, cs) present;
- `lint:yaml` and `lint:twig` pass.

**Upstream fixes** (`tests/test_addons.py`)
- the sieve folder and the learning scripts are not world-writable;
- a message is moved to Junk over IMAP: the learning script runs, `rspamc`
  gets the recipient (`--rcpt`), rspamd learns it as spam, and the tokens land
  in the **domain's** Bayes bucket (the one used for scanning), not in a
  per-address one.

**Calendars and contacts over ActiveSync** (`tests/test_activesync.py`)
- setup from the admin: DAV enabled in the settings, a calendar and an
  address book for alice, a calendar for bob shared **read-only** with alice,
  nothing for carol;
- folders on the phone: mail unchanged; alice's calendar is the default one,
  bob's shared calendar an additional one; contacts only for who has an
  address book; carol gets mail only;
- server → phone: events and a contact written over CalDAV/CardDAV arrive;
  a "2nd and 3rd of the month" recurrence arrives simplified (known Z-Push
  limit, checked so that a change is noticed);
- phone → server: an event and a contact created on the phone are stored;
  writing in the read-only calendar is refused (new event 6, change 7,
  deletion 8) and the calendar is unchanged on the server;
- an address book deleted in the admin while the phone still has the folder:
  a contact sent to it gets an error (not HTTP 500) and the folder is removed;
- DAV switched off in the settings: mail keeps working (a new message is
  delivered, only the calendar gets "hierarchy changed"), Ping answers, the
  FolderSync removes only calendars and contacts, a new phone gets mail only;
  switched on again: calendars, contacts and their content come back.

## Running the tests yourself

With Docker, from the repository:

```bash
docker build -f Dockerfile --build-arg POSTE_IO_VERSION=2.5.17 -t poste-addons-test .
sh tests/run.sh poste-addons-test
```

A run takes about three minutes after the build. The same script works on a
Pro image built with `Dockerfile-pro`.
