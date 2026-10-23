# Upstream fixes

> **Adopted upstream.** Poste.io **2.5.20** ships all three fixes (the
> learning scripts call `rspamc --rcpt`, remove their temporary file and are
> no longer world-writable), so the `upstream-fixes` add-on is gone from the
> Dockerfiles. On a base older than 2.5.20, build from
> [release 1.2.0](https://github.com/atsanna/poste.io-addons/releases/tag/v1.2.0).
> The end-to-end tests still verify the learning behaviour on every build.
> The rest of this page documents the three problems as they were.

Three problems of the Poste.io image around spam learning: moving a message
to **Junk** (or out of it) is supposed to teach rspamd, and up to 2.5.19 it
did not (fixes 1 and 2); and every message learned stayed behind in `/tmp`
(fix 3). The first two were reported to Poste.io; releases up to 1.2.0 carried
a workaround, marked `UPSTREAM-FIX`, removed now that the base image fixes
them.

## 1. The learning scripts are world-writable

In the base image `/etc/dovecot/sieve` and the `spam` / `ham` pipe scripts are
`0777`. Dovecot refuses to execute a world-writable program
(`executable ... is world-writable` in `/data/log/mail.log`), so the learning
never happens.

**Fix**: `chmod 0755` on the folder and the two scripts, `0644` on the `.sieve`
files.

## 2. Junk learning goes into a bucket nobody reads

Poste.io configures the rspamd Bayes classifier **per domain** (`per_user`
returns the recipient domain), so scanning reads the key `RS<domain>`. The Junk
scripts, however, call `rspamc learn_spam` / `learn_ham` **without a
recipient**: rspamd then learns into `RS<address>`, which no scan ever reads.

**Fix**: imapsieve passes the IMAP user to the scripts
(`pipe :copy "spam" ["${IMAPUSER}"]`) and the scripts call
`rspamc --rcpt "$1" learn_spam`, so the learning lands in the domain bucket.
The block ends with `grep -q` checks: if Poste.io changes the scripts, the
build fails instead of shipping a fix that no longer applies.

## 3. Every learned message stays in /tmp

The `spam` / `ham` scripts copy the message into a temporary file
(`input_tmpfile=$(mktemp)`) to give it to `rspamc` twice, and never remove it.
Each message moved into or out of Junk leaves a copy of itself in the
container's `/tmp`, readable by the `mail` user, until the container is
recreated.

**Fix**: right after the `mktemp` line the scripts get

```sh
trap 'rm -f "$input_tmpfile"' EXIT
trap 'exit 1' HUP INT TERM
```

so the file is removed when the script ends, also when it is stopped. As in
fix 2, a `grep` check fails the build if the scripts change.

## Are they still needed?

No, from Poste.io 2.5.20 (checked on `poste.io/mailserver:2.5.20` and
`analogic/poste.io:2.5.20`, scripts of 2026-10-21). On an older base, look at
the base image:

```bash
docker run --rm --entrypoint sh poste.io/mailserver:<VERSION> -c \
  'ls -l /etc/dovecot/sieve; grep -n "pipe :copy" /etc/dovecot/sieve/*.sieve; grep -n "rspamc.*learn_\|tmpfile" /etc/dovecot/sieve/spam /etc/dovecot/sieve/ham'
```

(`analogic/poste.io:<VERSION>` for the open source edition.)

- scripts `-rwxr-xr-x` → remove fix 1;
- `pipe` with an argument and `rspamc` with `--rcpt` (or an equivalent
  solution) → remove fix 2;
- the scripts remove their temporary file (`rm`, a `trap`, or no temporary
  file at all) → remove fix 3.

If the build fails on the `grep` checks instead, the scripts changed: read them
before adapting the block.

## Checking that learning works

Move a spam message to Junk, then in the container:

```bash
redis-cli -s /var/run/redis/redis.sock hget RS<your-domain> learns_spam
```

The counter of **your domain** must increase. (`rspamc stat` sums all keys and
can be misleading.) Bayes starts classifying after 200 learned messages of each
kind.
