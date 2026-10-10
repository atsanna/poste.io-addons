"""End-to-end tests of the External Mailboxes, dark theme and upstream-fixes
add-ons, run inside a test container by run.sh. An add-on that is not in the
image is reported as SKIP. Every check prints PASS or FAIL with what it found;
the exit code is the number of failures."""
import imaplib, os, re, ssl, stat, subprocess, sys, time
sys.path.insert(0, "/tests")
from panel import Panel, env

E = env()
ADMIN = Panel(E["ALICE"], E["PA"])          # alice is super admin
CTX = ssl._create_unverified_context()
FAILS = []


def check(name, ok, found):
    print("%s  %s  (%s)" % ("PASS" if ok else "FAIL", name, found), flush=True)
    if not ok:
        FAILS.append(name)


def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout


def mode(path):
    st = os.stat(path)
    return "%o %s" % (stat.S_IMODE(st.st_mode), "mail" if st.st_uid == int(sh("id -u mail")) else st.st_uid)


def imap(user, password):
    m = imaplib.IMAP4_SSL("localhost", 993, ssl_context=CTX)
    m.login(user, password)
    return m


def wait_for(user, password, subject, seconds=60):
    for _ in range(seconds // 3):
        m = imap(user, password)
        m.select("INBOX")
        typ, d = m.search(None, '(SUBJECT "%s")' % subject)
        m.logout()
        if d[0]:
            return True
        time.sleep(3)
    return False


def external_mailboxes():
    if not os.path.exists("/opt/admin/src/Base/Controller/ExternalMailboxController.php"):
        print("SKIP  External Mailboxes: not in this image")
        return
    print("--- External Mailboxes")
    url, html = ADMIN.get("/admin/")
    check("menu entries in the admin", "/admin/external-mailboxes/" in html and "/admin/external-mailbox-logs/" in html, url)
    for path in ("/admin/external-mailboxes/", "/admin/external-mailboxes/new", "/admin/external-mailbox-logs/"):
        url, html = ADMIN.get(path)
        check("page %s" % path, url.endswith(path) and "<form" in html or "table" in html, url)
    check("getmail installed", "getmail" in sh("getmail --version 2>&1").lower(), sh("getmail --version 2>&1").strip())
    lines = [l for l in sh("crontab -u mail -l").splitlines() if "cron.sh" in l and not l.startswith("#")]
    check("schedule in the crontab of mail (5 intervals)", len(lines) == 5, len(lines))
    check("nothing scheduled for root", "cron.sh" not in sh("crontab -u root -l 2>&1"), "root")
    folders = ["/data/var/getmail/%s" % d for d in ("5min", "10min", "15min", "30min", "60min", "disabled")] + ["/data/log/getmail"]
    modes = {f: mode(f) for f in folders}
    check("getmail folders 0750, owned by mail", all(m == "750 mail" for m in modes.values()), set(modes.values()))

    form = {"form[email_address]": E["BOB"], "form[type]": "SimpleIMAPRetriever", "form[server]": "localhost",
            "form[port]": "143", "form[username]": E["BOB"], "form[password]": E["PB"], "form[mailboxes]": "INBOX",
            "form[deliveryto]": E["ALICE"], "form[checkevery]": "5min"}
    ADMIN.submit("/admin/external-mailboxes/new", form, drop=("_token",))
    check("form without the CSRF token is refused (403, nothing saved)",
          ADMIN.status == 403 and not os.path.exists("/data/var/getmail/5min/%s.cfg" % E["BOB"]), ADMIN.status)
    ADMIN.submit("/admin/external-mailboxes/new", form)
    cfg = "/data/var/getmail/5min/%s.cfg" % E["BOB"]
    check("mailbox saved as a getmail file, 0600, owned by mail", os.path.exists(cfg) and mode(cfg) == "600 mail",
          mode(cfg) if os.path.exists(cfg) else "missing")
    text = open(cfg).read() if os.path.exists(cfg) else ""
    check("one IMAP folder written as a tuple", 'mailboxes = ("INBOX",)' in text, re.findall(r"^mailboxes = .*$", text, re.M))
    url, html = ADMIN.get("/admin/external-mailboxes/%s/edit" % E["BOB"])
    check("the password is never sent back to the browser", E["PB"] not in html, url)

    m = imap(E["BOB"], E["PB"])
    m.append("INBOX", None, None, b"From: test@example.net\r\nTo: bob@example.test\r\nSubject: Fetched by getmail\r\n\r\nhello\r\n")
    m.logout()
    sh("cd /data/var/getmail && runuser -u mail -- /data/var/getmail/cron.sh 5min")
    check("cron.sh as mail fetches the message and delivers it", wait_for(E["ALICE"], E["PA"], "Fetched by getmail"),
          sh("tail -n 1 /data/log/getmail/getmail.log").strip())
    url, html = ADMIN.get("/admin/external-mailbox-logs/")
    check("the logs page shows the getmail log", "retrieved" in html, url)


def dark_theme():
    if not os.path.exists("/opt/admin/public/dark-theme.css"):
        print("SKIP  dark theme: not in this image")
        return
    print("--- dark theme")
    base = open("/opt/admin/templates/base.html.twig").read()
    check("two hooks in base.html.twig, once each",
          base.count("Base/DarkTheme/_head.html.twig") == 1 and base.count("Base/DarkTheme/_toggle.html.twig") == 1, "base.html.twig")
    r = subprocess.run(["curl", "-sk", "-D", "-", "https://localhost/admin/dark-theme.css"], capture_output=True, text=True).stdout
    check("stylesheet served as text/css", " 200" in r.split("\n")[0] and "text/css" in r.lower() and "[data-bs-theme=dark]" in r, r.split("\n")[0])
    login = subprocess.run(["curl", "-sk", "https://localhost/admin/login"], capture_output=True, text=True).stdout
    check("login page: stylesheet and theme script in <head>",
          "dark-theme.css" in login and "posteDarkTheme" in login and login.index("dark-theme.css") < login.index("</head>"), "login")
    url, html = ADMIN.get("/admin/")
    check("theme switch in the top bar", 'id="theme-toggle"' in html, url)
    for lang in ("en", "cs"):
        t = open("/opt/admin/translations/Base/messages+intl-icu.%s.yml" % lang).read()
        check("translations (%s)" % lang, re.search(r"^theme:", t, re.M) is not None, lang)
    out = sh("cd /opt/admin && runuser -u mail -- php bin/console lint:yaml translations --env=prod 2>&1; "
             "runuser -u mail -- php bin/console lint:twig templates/Base/DarkTheme --env=prod 2>&1")
    check("translations and templates are valid (lint)", "ERROR" not in out and out.count("[OK]") == 2, out.count("[OK]"))


def upstream_fixes():
    sieve = "/etc/dovecot/sieve/report-spam.sieve"
    if not (os.path.exists(sieve) and "IMAPUSER" in open(sieve).read()):
        print("SKIP  upstream fixes: not in this image")
        return
    print("--- upstream fixes (spam learning)")
    modes = {p: mode(p).split()[0] for p in ("/etc/dovecot/sieve", "/etc/dovecot/sieve/spam", "/etc/dovecot/sieve/ham")}
    check("fix 1: sieve folder and learning scripts not world-writable (755)", set(modes.values()) == {"755"}, modes)
    body = " ".join("word%d lorem ipsum dolor sit amet consectetur" % i for i in range(40))
    m = imap(E["ALICE"], E["PA"])
    m.append("INBOX", None, None, ("From: spammer@example.net\r\nTo: alice@example.test\r\nSubject: Cheap offer\r\n"
                                   "Message-ID: <learn-test@example.net>\r\n\r\n" + body + "\r\n").encode())
    m.select("INBOX")
    typ, d = m.search(None, '(SUBJECT "Cheap offer")')
    m.copy(d[0].split()[0], "Junk")
    m.logout()
    learned = ""
    for _ in range(20):
        learned = sh("grep -a 'learned message as spam: learn-test@example.net' /data/log/rspamd/rspamd.log")
        if learned:
            break
        time.sleep(3)
    run = sh("grep -a 'rspamc --rcpt %s learn_spam' /data/log/mail.log" % E["ALICE"])
    check("fix 1: moving to Junk runs the learning script", run != "", run.strip()[-80:])
    check("fix 2: rspamc gets the recipient (--rcpt) and rspamd learns", run != "" and learned != "", learned.strip()[-60:])
    redis = "redis-cli -s /var/run/redis/redis.sock --scan --pattern '%s'"
    dom, user = sh(redis % "RSexample.test_*").split(), sh(redis % ("RS%s*" % E["ALICE"])).split()
    check("fix 2: learned into the domain's Bayes bucket, the one used for scanning", len(dom) > 0 and len(user) == 0,
          "domain keys %d, per-address keys %d" % (len(dom), len(user)))


for group in (external_mailboxes, dark_theme, upstream_fixes):
    try:
        group()
    except Exception as ex:          # a surprise is a failure of that group, the others still run
        check("%s ran to the end" % group.__name__, False, "%s: %s" % (type(ex).__name__, ex))
print("FAILURES: %d %s" % (len(FAILS), FAILS))
sys.exit(len(FAILS))
