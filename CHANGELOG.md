# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

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
