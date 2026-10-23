# Opravy upstreamu {#upstream-fixes}

> **Převzato upstreamem.** Poste.io **2.5.20** obsahuje všechny tři opravy
> (učicí skripty volají `rspamc --rcpt`, mažou svůj dočasný soubor a už
> nejsou zapisovatelné pro všechny), takže doplněk `upstream-fixes` z
> Dockerfilů zmizel. Na základním image starším než 2.5.20 sestavujte z
> [vydání 1.2.0](https://github.com/atsanna/poste.io-addons/releases/tag/v1.2.0).
> End-to-end testy chování učení dál ověřují při každém sestavení.
> Zbytek této stránky popisuje tři problémy tak, jak byly.

Tři problémy image Poste.io kolem učení spamu: přesunutí zprávy do **Junk**
(nebo z ní) má rspamd učit, a do verze 2.5.19 to nedělalo (opravy 1 a 2); a
každá naučená zpráva zůstala ležet v `/tmp` (oprava 3). První dva byly
nahlášeny Poste.io; vydání do 1.2.0 obsahovala obejití, označené
`UPSTREAM-FIX`, odstraněné teď, když je základní image opravuje.

## 1. Učicí skripty jsou zapisovatelné pro všechny {#1-the-learning-scripts-are-world-writable}

V základním image mají `/etc/dovecot/sieve` a skripty roury `spam` / `ham`
režim `0777`. Dovecot odmítá spustit program zapisovatelný pro všechny
(`executable ... is world-writable` v `/data/log/mail.log`), takže k učení
nikdy nedojde.

**Oprava**: `chmod 0755` na složku a oba skripty, `0644` na soubory `.sieve`.

## 2. Učení z Junk jde do úložiště, které nikdo nečte {#2-junk-learning-goes-into-a-bucket-nobody-reads}

Poste.io konfiguruje Bayesův klasifikátor rspamd **pro každou doménu**
(`per_user` vrací doménu příjemce), takže kontrola pošty čte klíč
`RS<domain>`. Skripty pro Junk však volají `rspamc learn_spam` / `learn_ham`
**bez příjemce**: rspamd pak učí do `RS<address>`, který žádná kontrola nikdy
nečte.

**Oprava**: imapsieve předá skriptům uživatele IMAP
(`pipe :copy "spam" ["${IMAPUSER}"]`) a skripty volají
`rspamc --rcpt "$1" learn_spam`, takže učení skončí v úložišti domény.
Blok končí kontrolami `grep -q`: pokud Poste.io skripty změní, sestavení
selže, místo aby dodalo opravu, která už neplatí.

## 3. Každá naučená zpráva zůstane v /tmp {#3-every-learned-message-stays-in-tmp}

Skripty `spam` / `ham` zkopírují zprávu do dočasného souboru
(`input_tmpfile=$(mktemp)`), aby ji mohly předat `rspamc` dvakrát, a nikdy ho
neodstraní. Každá zpráva přesunutá do Junk nebo z ní tak zanechá svou kopii
v `/tmp` kontejneru, čitelnou pro uživatele `mail`, dokud se kontejner znovu
nevytvoří.

**Oprava**: hned za řádek s `mktemp` dostanou skripty

```sh
trap 'rm -f "$input_tmpfile"' EXIT
trap 'exit 1' HUP INT TERM
```

takže se soubor odstraní, když skript skončí, i když je zastaven. Stejně
jako u opravy 2 kontrola `grep` nechá sestavení selhat, pokud se skripty
změní.

## Jsou ještě potřeba? {#are-they-still-needed}

Ne, od Poste.io 2.5.20 (ověřeno na `poste.io/mailserver:2.5.20` a
`analogic/poste.io:2.5.20`, skripty z 2026-10-21). Na starším základním
image se podívejte do základního image:

```bash
docker run --rm --entrypoint sh poste.io/mailserver:<VERSION> -c \
  'ls -l /etc/dovecot/sieve; grep -n "pipe :copy" /etc/dovecot/sieve/*.sieve; grep -n "rspamc.*learn_\|tmpfile" /etc/dovecot/sieve/spam /etc/dovecot/sieve/ham'
```

(`analogic/poste.io:<VERSION>` pro edici open source.)

- skripty `-rwxr-xr-x` → odstraňte opravu 1;
- `pipe` s argumentem a `rspamc` s `--rcpt` (nebo rovnocenné řešení) →
  odstraňte opravu 2;
- skripty svůj dočasný soubor odstraňují (`rm`, `trap` nebo žádný dočasný
  soubor) → odstraňte opravu 3.

Pokud místo toho sestavení selže na kontrolách `grep`, skripty se změnily:
než blok upravíte, přečtěte si je.

## Ověření, že učení funguje {#checking-that-learning-works}

Přesuňte spamovou zprávu do Junk a pak v kontejneru:

```bash
redis-cli -s /var/run/redis/redis.sock hget RS<your-domain> learns_spam
```

Počítadlo **vaší domény** musí vzrůst. (`rspamc stat` sčítá všechny klíče a
může být zavádějící.) Bayes začne klasifikovat po 200 naučených zprávách
každého druhu.
