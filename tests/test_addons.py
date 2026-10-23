"""End-to-end tests of the External Mailboxes, ActiveSync Logs and dark theme
add-ons, plus the spam learning of the base image (fixes adopted upstream in
Poste.io 2.5.20), run inside a test container by run.sh. An add-on that is not
in the image is reported as SKIP. Every check prints PASS or FAIL with what it
found; the exit code is the number of failures."""
import imaplib, os, pwd, re, ssl, stat, subprocess, sys, time, urllib.parse
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
    # "Read all" off: with it on and "Delete" off getmail warns on every run that
    # it fetches everything again, and the warning lands in errors.log
    ADMIN.submit("/admin/external-mailboxes/new", form, drop=("form[optionsreadall]",))
    cfg = "/data/var/getmail/5min/%s.cfg" % E["BOB"]
    check("mailbox saved as a getmail file, 0600, owned by mail", os.path.exists(cfg) and mode(cfg) == "600 mail",
          mode(cfg) if os.path.exists(cfg) else "missing")
    text = open(cfg).read() if os.path.exists(cfg) else ""
    check("one IMAP folder written as a tuple", 'mailboxes = ("INBOX",)' in text, re.findall(r"^mailboxes = .*$", text, re.M))
    url, html = ADMIN.get("/admin/external-mailboxes/%s/edit" % E["BOB"])
    check("the password is never sent back to the browser", E["PB"] not in html, url)
    read_all_rule(form)

    m = imap(E["BOB"], E["PB"])
    # a well-formed message: it is scanned by rspamd on the way (rspamd_filter)
    m.append("INBOX", None, None, b"From: Test Sender <test@example.net>\r\nTo: Bob <bob@example.test>\r\n"
             b"Subject: Fetched by getmail\r\nDate: Sun, 11 Oct 2026 09:00:00 +0000\r\n"
             b"Message-ID: <fetched-by-getmail@example.net>\r\nMIME-Version: 1.0\r\n"
             b"Content-Type: text/plain; charset=utf-8\r\n\r\n"
             b"Hello Bob,\r\n\r\nthis message waits in your mailbox until getmail fetches it and delivers it to Alice.\r\n\r\nTest\r\n")
    m.logout()
    sh("cd /data/var/getmail && runuser -u mail -- /data/var/getmail/cron.sh 5min")
    check("cron.sh as mail fetches the message and delivers it", wait_for(E["ALICE"], E["PA"], "Fetched by getmail"),
          sh("tail -n 1 /data/log/getmail/getmail.log").strip())
    url, html = ADMIN.get("/admin/external-mailbox-logs/")
    check("the logs page shows the getmail log", "retrieved" in html, url)
    rspamd_filter()
    getmail_errors(form)
    log_rotation()


def fetched_headers(subject, seconds=60):
    """Folder and headers of alice's message with this subject, in Junk or INBOX."""
    for _ in range(seconds // 3):
        m = imap(E["ALICE"], E["PA"])
        for folder in ("Junk", "INBOX"):
            if m.select(folder)[0] != "OK":
                continue
            typ, d = m.search(None, '(SUBJECT "%s")' % subject)
            if d[0]:
                typ, h = m.fetch(d[0].split()[-1], "(BODY.PEEK[HEADER])")
                m.logout()
                return folder, h[0][1].decode(errors="replace")
        m.logout()
        time.sleep(3)
    return None, ""


def rspamd_filter():
    """Fetched mail goes through rspamd before delivery (getmail filter):
    Haraka's submission, where getmail delivers, does not scan local mail."""
    cfg = "/data/var/getmail/5min/%s.cfg" % E["BOB"]
    text = open(cfg).read() if os.path.exists(cfg) else ""
    check("the getmail file has the rspamd filter",
          "[filter1]" in text and "path = /usr/local/bin/getmail-rspamd" in text and 'arguments = ("%s",)' % E["ALICE"] in text,
          re.findall(r"^\[filter1\]$|^path = .*getmail-rspamd$", text, re.M))
    folder, h = fetched_headers("Fetched by getmail")
    check("a fetched message is scanned by rspamd (X-Rspamd-Score)", re.search(r"^X-Rspamd-Score: -?[0-9.]+\r?$", h, re.M),
          "%s: %s" % (folder, re.findall(r"^X-Rspamd-(?:Score|Report): .*$", h, re.M)))

    m = imap(E["BOB"], E["PB"])
    m.append("INBOX", None, None, b"From: test@example.net\r\nTo: bob@example.test\r\nSubject: Fetched spam\r\n\r\n"
             b"XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X\r\n")
    m.logout()
    sh("cd /data/var/getmail && runuser -u mail -- /data/var/getmail/cron.sh 5min")
    folder, h = fetched_headers("Fetched spam")
    check("a fetched spam (GTUBE) is delivered with the spam subject",
          re.search(r"^Subject: \*\*\*\*\*SPAM\*\*\*\*\* Fetched spam", h, re.M),
          "%s: %s" % (folder, re.findall(r"^Subject: .*$", h, re.M)))


def read_all_rule(form):
    """"Read all" only with "Delete message": otherwise getmail fetches again,
    at every run, every message left on the server."""
    url, html = ADMIN.get("/admin/external-mailboxes/new")
    f = [x for x in Panel.parse(html).forms if x["method"].lower() == "post"][0]
    types = {n: t for n, v, t in f["fields"] if n}
    check("new mailbox: Read all off by default", types.get("form[optionsreadall]") == "checkbox:off", types.get("form[optionsreadall]"))
    check("the form switches Read all off when Delete is off", "toggleReadAll" in html, url)

    # a mailbox that is never scheduled: nothing is fetched from it
    other = dict(form, **{"form[email_address]": "readall@example.test", "form[checkevery]": "disabled"})
    cfg = "/data/var/getmail/disabled/readall@example.test.cfg"
    url, html = ADMIN.submit("/admin/external-mailboxes/new", dict(other, **{"form[optionsreadall]": "1"}),
                             drop=("form[optionsdelete]",))
    check("Read all without Delete is refused (400, nothing saved)",
          ADMIN.status == 400 and "Read all" in html and not os.path.exists(cfg), (ADMIN.status, html.strip()[:120]))
    ADMIN.submit("/admin/external-mailboxes/new", dict(other, **{"form[optionsreadall]": "1", "form[optionsdelete]": "1"}))
    text = open(cfg).read() if os.path.exists(cfg) else ""
    check("Read all with Delete is accepted", re.search(r"^read_all = true$", text, re.M) and re.search(r"^delete = true$", text, re.M),
          re.findall(r"^(?:read_all|delete) = .*$", text, re.M) or "missing")
    check("the comment above read_all says what it does", "# true: every message on the server" in text, "comment")
    url, html = ADMIN.get("/admin/external-mailboxes/readall@example.test")
    d = [x for x in Panel.parse(html).forms if x["action"].endswith("/delete")]
    if d:
        ADMIN.post(d[0]["action"], {n: v or "" for n, v, t in d[0]["fields"] if n and not t.endswith(":off")})
    check("test mailbox deleted from the admin", not os.path.exists(cfg), ADMIN.status)


def form_post(path, action_suffix, values, drop=()):
    """POST the form of a page whose action ends with action_suffix, keeping
    what the page has (like a browser), changing values, leaving out drop."""
    url, html = ADMIN.get(path)
    f = [x for x in Panel.parse(html).forms if x["action"].endswith(action_suffix)][0]
    data = {n: (v or "") for n, v, t in f["fields"] if n and not t.endswith(":off") and t not in ("submit", "button", "file")}
    data.update(values)
    for n in drop:
        data.pop(n, None)
    return ADMIN.post(f["action"], data)


def log_rotation():
    """getmail.log and errors.log are rotated by Poste.io's nightly logrotate,
    with a rule that a root script writes from the settings saved in the admin."""
    rule, conf = "/etc/logrotate.d/external-mailboxes", "/data/var/getmail/log-rotation.ini"
    gen = "/usr/local/bin/external-mailboxes-logrotate"
    read = lambda f: open(f).read() if os.path.exists(f) else ""
    text = read(rule)
    check("default rotation rule: getmail.log weekly, errors.log at 1M, 4 copies",
          "weekly" in text and "size 1M" in text and text.count("rotate 4") == 2, re.findall(r"^\s+(weekly|size .*|rotate .*)$", text, re.M))
    check("the rule is rewritten by root at 00:00, before logrotate", "0 0 * * * root " + gen in read("/etc/cron.d/external-mailboxes-logrotate"),
          read("/etc/cron.d/external-mailboxes-logrotate").strip()[-60:])
    url, html = ADMIN.get("/admin/external-mailbox-logs/")
    check("Rotation settings in the logs page", 'id="em-rotation"' in html and 'name="getmail_every"' in html, url)

    form_post("/admin/external-mailbox-logs/", "/rotation", {"getmail_every": "daily"}, drop=("_token",))
    check("settings without the CSRF token are refused (nothing saved)", not os.path.exists(conf), read(conf)[:60] or "no file")
    form_post("/admin/external-mailbox-logs/", "/rotation", {"getmail_every": "daily", "errors_size": "5M", "keep": "8"})
    saved = read(conf)
    check("settings saved (0640, owned by mail)", "getmail_every=daily" in saved and "errors_size=5M" in saved and "keep=8" in saved
          and mode(conf) == "640 mail", (mode(conf) if saved else "missing", re.findall(r"^\w+=.*$", saved, re.M)))
    sh(gen)
    text = read(rule)
    check("the root script applies them: daily, errors at 5M, 8 copies",
          "daily" in text and "size 5M" in text and text.count("rotate 8") == 2, re.findall(r"^\s+(daily|weekly|size .*|rotate .*)$", text, re.M))
    form_post("/admin/external-mailbox-logs/", "/rotation", {"keep": "99"})
    check("a value not offered by the form is refused (nothing changed)", read(conf) == saved, re.findall(r"^keep=.*$", read(conf), re.M))

    # a settings file changed behind the admin: allowed values only, nothing executed
    with open(conf, "w") as f:
        f.write("getmail_every=hourly\nkeep=$(touch /tmp/rotation-pwned)\nerrors_size=1M;touch /tmp/rotation-pwned\n")
    sh(gen)
    text = read(rule)
    check("a tampered settings file gives the defaults and runs nothing",
          "weekly" in text and "size 1M" in text and text.count("rotate 4") == 2 and not os.path.exists("/tmp/rotation-pwned"),
          (re.findall(r"^\s+(daily|weekly|size .*|rotate .*)$", text, re.M), os.path.exists("/tmp/rotation-pwned")))

    form_post("/admin/external-mailbox-logs/", "/rotation", {"getmail_every": "weekly", "errors_size": "1M", "keep": "4"}, drop=("enabled",))
    sh(gen)
    off = subprocess.run("logrotate -d %s" % rule, shell=True, capture_output=True, text=True)
    check("rotation switched off: no getmail log in the rule", "enabled=0" in read(conf) and "/data/log/getmail" not in read(rule) and off.returncode == 0,
          (re.findall(r"^enabled=.*$", read(conf), re.M), off.returncode))

    form_post("/admin/external-mailbox-logs/", "/rotation", {"enabled": "1", "getmail_every": "weekly", "errors_size": "1M", "keep": "4"})
    sh(gen)
    out = subprocess.run("logrotate -f -s /tmp/logrotate.test %s" % rule, shell=True, capture_output=True, text=True)
    log, old = "/data/log/getmail/getmail.log", "/data/log/getmail/getmail.log.1"
    check("forced rotation: no error, getmail.log.1 kept, getmail.log recreated 0640 owned by mail",
          out.returncode == 0 and not out.stderr.strip() and os.path.exists(old) and os.path.exists(log) and mode(log) == "640 mail",
          (out.returncode, out.stderr.strip()[:120], os.path.exists(old), mode(log) if os.path.exists(log) else "missing"))
    # cron.sh skips an account whose lock is held (flock -n): if cron's own run
    # of the same minute holds it, this one does nothing; try again a few times
    for _ in range(6):
        sh("cd /data/var/getmail && runuser -u mail -- /data/var/getmail/cron.sh 5min")
        if os.path.getsize(log) > 0:
            break
        time.sleep(5)
    check("getmail writes to the new getmail.log", os.path.getsize(log) > 0, os.path.getsize(log))
    url, html = ADMIN.get("/admin/external-mailbox-logs/")
    check("the page shows the old copies", re.search(r"[1-9][0-9]* old copies", html) is not None,
          re.findall(r"getmail\.log [^<]*old copies[^<]*", html)[:1])


def first_row(html):
    """The first row of the logs table, with its level class."""
    return html.split('data-log="1"', 1)[1].split("</tr>", 1)[0] if 'data-log="1"' in html else ""


def getmail_errors(form):
    """What getmail prints on stderr goes to errors.log ("Getmail Errors" log
    type): the admin cannot read mail.log. The getmail view flags recent errors."""
    errlog = "/data/log/getmail/errors.log"
    summary = "error or warning line(s) in the last 24 hours"
    check("a run without errors leaves errors.log empty, 0640, owned by mail",
          os.path.exists(errlog) and mode(errlog) == "640 mail" and os.path.getsize(errlog) == 0,
          "%s, %d bytes" % (mode(errlog), os.path.getsize(errlog)) if os.path.exists(errlog) else "missing")
    url, html = ADMIN.get("/admin/external-mailbox-logs/")
    check("log types: Getmail Errors is in the menu", 'value="errors"' in html, url)
    check("getmail view: no error summary without errors", summary not in html, url)

    # carol's mailbox with a wrong password: getmail fails to log in
    bad = dict(form, **{"form[email_address]": E["CAROL"], "form[username]": E["CAROL"],
                        "form[password]": "wrong-" + E["PC"], "form[checkevery]": "10min"})
    ADMIN.submit("/admin/external-mailboxes/new", bad)
    sh("cd /data/var/getmail && runuser -u mail -- /data/var/getmail/cron.sh 10min")
    text = open(errlog).read() if os.path.exists(errlog) else ""
    check("a login error is written to errors.log with its mailbox",
          "10min/%s.cfg: " % E["CAROL"] in text and "login" in text.lower(), text.strip()[:200])
    url, html = ADMIN.get("/admin/external-mailbox-logs/")
    row = first_row(html)
    check("getmail view: a red summary on top", row.startswith(' class="log-level-error"') and summary in row,
          re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", row)).strip()[:200])
    url, html = ADMIN.get("/admin/external-mailbox-logs/?type=errors")
    row = first_row(html)
    check("Getmail Errors view: the error, as an error", row.startswith(' class="log-level-error"') and E["CAROL"] in row,
          re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", row)).strip()[:200])

    # remove carol's mailbox from the admin, so that cron adds nothing more
    url, html = ADMIN.get("/admin/external-mailboxes/%s" % E["CAROL"])
    f = [x for x in Panel.parse(html).forms if x["action"].endswith("/delete")]
    if f:
        ADMIN.post(f[0]["action"], {n: v or "" for n, v, t in f[0]["fields"] if n and not t.endswith(":off")})
    check("carol's mailbox deleted from the admin", not os.path.exists("/data/var/getmail/10min/%s.cfg" % E["CAROL"]),
          ADMIN.status)

    size = os.path.getsize(errlog)
    ADMIN.submit("/admin/external-mailbox-logs/?type=errors", {}, drop=("_token",))
    check("Clear without the CSRF token: errors.log unchanged", os.path.getsize(errlog) == size, size)
    ADMIN.submit("/admin/external-mailbox-logs/?type=errors", {})
    check("Clear on Getmail Errors empties errors.log", os.path.getsize(errlog) == 0, os.path.getsize(errlog))
    url, html = ADMIN.get("/admin/external-mailbox-logs/")
    check("getmail view: no summary after Clear", summary not in html, url)


def activesync_logs():
    """The ActiveSync Logs page reads the Z-Push logs (www-data, 0644: read, never
    cleared) and saves their rotation settings; a root script writes the rule."""
    ctrl = "/opt/admin/src/Base/Controller/ActiveSyncLogsController.php"
    if not os.path.exists(ctrl):
        print("SKIP  ActiveSync Logs: not in this image")
        return
    print("--- ActiveSync Logs")
    page = "/admin/activesync-logs/"
    rule, conf = "/etc/logrotate.d/activesync-logs", "/data/var/activesync-logs/log-rotation.ini"
    gen = "/usr/local/bin/activesync-logs-logrotate"
    log, errlog = "/data/log/z-push/z-push.log", "/data/log/z-push/z-push-error.log"
    read = lambda f: open(f).read() if os.path.exists(f) else ""
    www = int(sh("id -u www-data"))
    owner = lambda p: "%o %s" % (stat.S_IMODE(os.stat(p).st_mode), "www-data" if os.stat(p).st_uid == www else os.stat(p).st_uid)
    text_of = lambda html: re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html)).strip()

    url, html = ADMIN.get("/admin/")
    menu = open("/opt/admin/templates/_menu.html.twig").read()
    check("menu entry right after User logs", page in html and 0 < menu.find("pro_path('auth_log')") < menu.find("activesync_logs"), url)
    check("settings folder made at start on the volume (0750, owned by mail)",
          os.path.isdir(os.path.dirname(conf)) and mode(os.path.dirname(conf)) == "750 mail",
          mode(os.path.dirname(conf)) if os.path.isdir(os.path.dirname(conf)) else "missing")
    text = read(rule)
    body = "\n".join(l for l in text.splitlines() if not l.lstrip().startswith("#"))   # the comments mention copytruncate
    check("default rotation rule: both Z-Push logs weekly, 4 copies, as www-data",
          body.count("weekly") == 2 and body.count("rotate 4") == 2 and body.count("su www-data www-data") == 2
          and body.count("create 0644 www-data www-data") == 2 and "copytruncate" not in body,
          re.findall(r"^\s+(weekly|rotate .*|su .*)$", text, re.M))
    check("the rule is rewritten by root at 00:00, before logrotate", "0 0 * * * root " + gen in read("/etc/cron.d/activesync-logs-logrotate"),
          read("/etc/cron.d/activesync-logs-logrotate").strip()[-60:])

    # a real Z-Push error: an ActiveSync request with a wrong password
    sync = "https://localhost/Microsoft-Server-ActiveSync?Cmd=FolderSync&User=%s&DeviceId=LOGTEST1&DeviceType=TestPhone" % E["BOB"]
    subprocess.run(["curl", "-sk", "-m", "90", "-o", "/dev/null", "-u", E["BOB"] + ":wrong-" + E["PB"], "-X", "POST",
                    "-H", "MS-ASProtocolVersion: 14.0", "-H", "Content-Type: application/vnd.ms-sync.wbxml", sync])
    check("Z-Push logs stay www-data 0644", os.path.exists(log) and owner(log) == "644 www-data", owner(log) if os.path.exists(log) else "missing")
    url, html = ADMIN.get(page + "?level=error&account=" + E["BOB"])
    row = first_row(html)
    check("a wrong ActiveSync password is shown as an error, with its account",
          row.startswith(' class="log-level-error"') and E["BOB"] in row and "Access denied" in row, text_of(row)[:200])

    # known lines, in Z-Push's format, to check the filters and the order
    now = time.strftime("%d/%m/%Y %H:%M:%S")
    lines = ["%s [ 4242] [ INFO] [%s] logtest-info <b>bold</b>" % (now, E["CAROL"]),
             "%s [ 4242] [WARN] [%s] logtest-warn" % (now, E["CAROL"]),
             "%s [ 4242] [ERROR] [%s] logtest-error version='2.7.5" % (now, E["ALICE"]),
             "' method='POST' httpcode='500'"]
    with open(log, "a") as f:
        f.write("\n".join(lines) + "\n")
    url, html = ADMIN.get(page)
    row = first_row(html)
    check("newest line first, a message on two lines kept together",
          "logtest-error" in row and "httpcode=&#039;500&#039;" in row and row.startswith(' class="log-level-error"'), text_of(row)[:200])
    check("log text is escaped (no HTML from the log)", "&lt;b&gt;bold&lt;/b&gt;" in html and "<b>bold</b>" not in html, "logtest-info")
    url, html = ADMIN.get(page + "?level=warning")
    check("level 'warnings and errors': WARN and ERROR, no INFO",
          "logtest-warn" in html and "logtest-error" in html and "logtest-info" not in html, url)
    url, html = ADMIN.get(page + "?level=error")
    check("level 'errors only': ERROR, no WARN", "logtest-error" in html and "logtest-warn" not in html, url)
    url, html = ADMIN.get(page + "?account=" + E["CAROL"])
    opts = re.findall(r'<option value="([^"]+@[^"]+)"', html)
    check("account menu from the log, and the filter",
          E["CAROL"] in opts and E["ALICE"] in opts and "logtest-info" in html and "logtest-error" not in html, opts)
    url, html = ADMIN.get(page + "?account=nobody@example.test")
    check("an account not in the menu is ignored (all accounts)", "logtest-error" in html and "logtest-info" in html, url)
    url, html = ADMIN.get(page + "?search=LOGTEST-WARN")
    check("search, case-insensitive", "logtest-warn" in html and "logtest-info" not in html, url)
    ADMIN.get(page + "?search=" + urllib.parse.quote("x'; touch /tmp/aslogs-pwned; echo '"))
    check("search text is not run by the shell", not os.path.exists("/tmp/aslogs-pwned"), os.path.exists("/tmp/aslogs-pwned"))
    url, html = ADMIN.get(page + "?type=errors")
    check("Z-Push errors log: errors and warnings, not INFO", "Access denied" in html and "logtest-info" not in html, url)
    actions = [x["action"] for x in Panel.parse(html).forms]
    check("no Clear form, a Rotation form (the logs belong to Z-Push)",
          not any(x.endswith("/clear") for x in actions) and any(x.endswith("/activesync-logs/rotation") for x in actions)
          and 'name="zpush_every"' in html, actions)

    # rotation settings
    form_post(page, "/rotation", {"zpush_every": "daily"}, drop=("_token",))
    check("settings without the CSRF token are refused (nothing saved)", not os.path.exists(conf), read(conf)[:60] or "no file")
    form_post(page, "/rotation", {"zpush_every": "daily", "errors_every": "monthly", "keep": "8"})
    saved = read(conf)
    check("settings saved (0640, owned by mail)", "zpush_every=daily" in saved and "errors_every=monthly" in saved and "keep=8" in saved
          and mode(conf) == "640 mail", (mode(conf) if saved else "missing", re.findall(r"^\w+=.*$", saved, re.M)))
    sh(gen)
    text = read(rule)
    check("the root script applies them: z-push.log daily, errors monthly, 8 copies",
          re.search(r"z-push\.log \{\s+daily", text) and re.search(r"z-push-error\.log \{\s+monthly", text) and text.count("rotate 8") == 2,
          re.findall(r"^\s+(daily|weekly|monthly|rotate .*)$", text, re.M))
    form_post(page, "/rotation", {"keep": "99"})
    check("a value not offered by the form is refused (nothing changed)", read(conf) == saved, re.findall(r"^keep=.*$", read(conf), re.M))
    with open(conf, "w") as f:
        f.write("zpush_every=hourly\nkeep=$(touch /tmp/aslogs-pwned)\nerrors_every=daily;touch /tmp/aslogs-pwned\n")
    sh(gen)
    text = read(rule)
    check("a tampered settings file gives the defaults and runs nothing",
          text.count("weekly") == 2 and text.count("rotate 4") == 2 and not os.path.exists("/tmp/aslogs-pwned"),
          (re.findall(r"^\s+(daily|weekly|monthly|rotate .*)$", text, re.M), os.path.exists("/tmp/aslogs-pwned")))
    form_post(page, "/rotation", {"zpush_every": "weekly", "errors_every": "weekly", "keep": "4"}, drop=("enabled",))
    sh(gen)
    off = subprocess.run("logrotate -d %s" % rule, shell=True, capture_output=True, text=True)
    check("rotation switched off: no Z-Push log in the rule", "enabled=0" in read(conf) and "/data/log/z-push" not in read(rule) and off.returncode == 0,
          (re.findall(r"^enabled=.*$", read(conf), re.M), off.returncode))
    form_post(page, "/rotation", {"enabled": "1", "zpush_every": "weekly", "errors_every": "weekly", "keep": "4"})
    sh(gen)
    out = subprocess.run("logrotate -f -s /tmp/logrotate-aslogs.test %s" % rule, shell=True, capture_output=True, text=True)
    check("forced rotation: no error, z-push.log.1 kept, z-push.log recreated 0644 owned by www-data",
          out.returncode == 0 and not out.stderr.strip() and os.path.exists(log + ".1") and os.path.exists(log) and owner(log) == "644 www-data",
          (out.returncode, out.stderr.strip()[:120], os.path.exists(log + ".1"), owner(log) if os.path.exists(log) else "missing"))
    subprocess.run(["curl", "-sk", "-m", "60", "-o", "/dev/null", "-u", E["ALICE"] + ":" + E["PA"], "-X", "OPTIONS",
                    "https://localhost/Microsoft-Server-ActiveSync"])
    check("Z-Push writes to the new z-push.log", os.path.getsize(log) > 0 and "Options request" in read(log), os.path.getsize(log))
    url, html = ADMIN.get(page + "?rotation=1")
    check("the page shows the old copies", re.search(r"z-push\.log [^<]* [1-9][0-9]* old copies", html) is not None,
          re.findall(r"z-push\.log [^<]*old copies[^<]*", html)[:1])
    out = sh("cd /opt/admin && runuser -u mail -- php bin/console lint:yaml translations --env=prod 2>&1; "
             "runuser -u mail -- php bin/console lint:twig templates/Base/ActiveSyncLogs --env=prod 2>&1")
    check("translations and templates are valid (lint)", "ERROR" not in out and out.count("[OK]") == 2, out.count("[OK]"))


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


def spam_learning():
    # Fixed upstream in Poste.io 2.5.20 (the former upstream-fixes add-on,
    # release <= 1.2.0): kept as a regression test of the base image.
    sieve = "/etc/dovecot/sieve/report-spam.sieve"
    if not (os.path.exists(sieve) and "IMAPUSER" in open(sieve).read()):
        print("SKIP  spam learning: scripts without the recipient (base older than 2.5.20 without the upstream-fixes add-on)")
        return
    print("--- spam learning (base image, fixes adopted upstream in 2.5.20)")
    modes = {p: mode(p).split()[0] for p in ("/etc/dovecot/sieve", "/etc/dovecot/sieve/spam", "/etc/dovecot/sieve/ham")}
    check("sieve folder and learning scripts not world-writable", all(int(m, 8) & 0o002 == 0 for m in modes.values()), modes)
    body = " ".join("word%d lorem ipsum dolor sit amet consectetur" % i for i in range(40))
    def learn_tmp():
        """The temporary files of the learning scripts (mktemp, owned by mail) now in /tmp."""
        uid = pwd.getpwnam("mail").pw_uid
        return {f for f in os.listdir("/tmp") if f.startswith("tmp.") and os.lstat("/tmp/" + f).st_uid == uid}
    tmp_before = learn_tmp()
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
    check("moving to Junk runs the learning script", run != "", run.strip()[-80:])
    check("rspamc gets the recipient (--rcpt) and rspamd learns", run != "" and learned != "", learned.strip()[-60:])
    redis = "redis-cli -s /var/run/redis/redis.sock --scan --pattern '%s'"
    dom, user = sh(redis % "RSexample.test_*").split(), sh(redis % ("RS%s*" % E["ALICE"])).split()
    check("learned into the domain's Bayes bucket, the one used for scanning", len(dom) > 0 and len(user) == 0,
          "domain keys %d, per-address keys %d" % (len(dom), len(user)))
    # the script goes on after learning (fuzzy_add): its file must be gone once it ends
    left = learn_tmp() - tmp_before
    for _ in range(15):
        if not left:
            break
        time.sleep(2)
        left = learn_tmp() - tmp_before
    check("the learning script leaves no copy of the message in /tmp", learned != "" and not left, sorted(left))


for group in (external_mailboxes, activesync_logs, dark_theme, spam_learning):
    try:
        group()
    except Exception as ex:          # a surprise is a failure of that group, the others still run
        check("%s ran to the end" % group.__name__, False, "%s: %s" % (type(ex).__name__, ex))
print("FAILURES: %d %s" % (len(FAILS), FAILS))
sys.exit(len(FAILS))
