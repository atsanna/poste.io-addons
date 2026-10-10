# Upstream fixes

Two problems of the Poste.io image that break spam learning: moving a message
to **Junk** (or out of it) is supposed to teach rspamd, and on 2.5.17 it does
not. Both were reported to Poste.io; the Dockerfiles carry a workaround, marked
`UPSTREAM-FIX`, to be removed when the base image fixes them.

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

## Are they still needed?

Before building a new Poste.io version, look at the base image:

```bash
docker run --rm --entrypoint sh poste.io/mailserver:<VERSION> -c \
  'ls -l /etc/dovecot/sieve; grep -n "pipe :copy" /etc/dovecot/sieve/*.sieve; grep -n "rspamc.*learn_" /etc/dovecot/sieve/spam /etc/dovecot/sieve/ham'
```

(`analogic/poste.io:<VERSION>` for the open source edition.)

- scripts `-rwxr-xr-x` → remove fix 1;
- `pipe` with an argument and `rspamc` with `--rcpt` (or an equivalent
  solution) → remove fix 2. If the build fails on the `grep` checks instead,
  the scripts changed: read them before adapting the block.

## Checking that learning works

Move a spam message to Junk, then in the container:

```bash
redis-cli -s /var/run/redis/redis.sock hget RS<your-domain> learns_spam
```

The counter of **your domain** must increase. (`rspamc stat` sums all keys and
can be misleading.) Bayes starts classifying after 200 learned messages of each
kind.
