"""End-to-end tests of the backup add-on, run inside the test container by
run.sh, with real destinations next to it (tests/backup-dest: Samba and SFTP;
rclone's S3 server) and a tmpfs at /backup/local as a mounted volume.

The admin is used like a browser (forms, CSRF tokens, redirects); then what
happened is checked where it matters: on the destinations, by listing the
snapshots and by restoring files and comparing them with the originals.
Cron runs the engine every minute in the container too: the tests wait for
the engine's lock instead of assuming who runs what.
Every check prints PASS or FAIL; the exit code is the number of failures."""
import fcntl, hashlib, json, os, re, shutil, stat, subprocess, sys, time, urllib.parse
sys.path.insert(0, "/tests")
from panel import Panel, env

FAILS = []


def check(name, ok, found):
    print("%s  %s  (%s)" % ("PASS" if ok else "FAIL", name, found), flush=True)
    if not ok:
        FAILS.append(name)


def sh(cmd, user=None, input=None):
    args = ["runuser", "-u", user, "--"] + cmd if user else cmd
    return subprocess.run(args, capture_output=True, text=True, input=input)


def owner(path):
    st = os.lstat(path)
    names = {}
    for n in ("root", "mail"):
        names[int(sh(["id", "-u", n]).stdout)] = n
    grp = {0: "root", int(sh(["id", "-g", "mail"]).stdout): "mail"}
    return "%s:%s %o" % (names.get(st.st_uid, st.st_uid), grp.get(st.st_gid, st.st_gid), stat.S_IMODE(st.st_mode))


if not os.path.exists("/usr/local/bin/poste-backup"):
    print("SKIP  backup: not in this image")
    print("FAILURES: 0 []")
    sys.exit(0)

E = env()
B = env("/tests/backup.env")
ADMIN = Panel(E["ALICE"], E["PA"])
BASE = "/admin/backup"
STATE = "/data/var/backup/engine/state.json"
CONFIG = "/data/var/backup/settings/config.json"


def form(path, suffix=None):
    """The POST form of a page (the one whose action ends with suffix, or the first)."""
    url, html = ADMIN.get(path)
    forms = [f for f in Panel.parse(html).forms if f["method"].lower() == "post"]
    if suffix:
        forms = [f for f in forms if f["action"].endswith(suffix)]
    return (forms[0] if forms else None), html


def post_form(path, values=None, suffix=None, drop=()):
    f, html = form(path, suffix)
    if f is None:
        return None, html
    data = {n: (v or "") for n, v, t in f["fields"] if n and not t.endswith(":off") and t not in ("submit", "button")}
    data.update(values or {})
    for n in drop:
        data.pop(n, None)
    action = f["action"] if f["action"].startswith("/") else path
    return ADMIN.post(action, data)


def config():
    return json.load(open(CONFIG)) if os.path.exists(CONFIG) else {"destinations": {}, "jobs": {}}


def state():
    return json.load(open(STATE)) if os.path.exists(STATE) else {}


def engine_idle(timeout=900):
    """Waits until no engine run holds the lock."""
    end = time.time() + timeout
    while time.time() < end:
        with open("/run/poste-backup.lock") as f:
            try:
                fcntl.flock(f, fcntl.LOCK_SH | fcntl.LOCK_NB)
                fcntl.flock(f, fcntl.LOCK_UN)
                return True
            except OSError:
                time.sleep(2)
    return False


def run_job(job_id):
    """Runs a job now from the command line (waiting for a cron tick in progress)."""
    for _ in range(3):
        engine_idle()
        r = sh(["poste-backup", "run", job_id])
        if "busy" not in r.stdout:
            return r
    return r


def new_destination(values):
    url, html = post_form(BASE + "/destinations/new", values)
    m = re.search(r"/destinations/([a-f0-9]{12})/edit", url or "")
    if not m and values.get("name") not in ("bad",):
        # not expected (seen three times in many runs, never reproduced): everything about it.
        # The text of the error boxes in full (it starts with an icon: up to the first tag it is empty)
        boxes = [re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", b)).strip()
                 for b in re.findall(r'class="[^"]*alert-danger[^"]*"[^>]*>(.*?)</div>', html, re.S)]
        print("DIAG  destination %r not saved: HTTP %s, %s, error boxes %s, fields sent %s" % (values.get("name"), ADMIN.status, url,
              boxes[:5], sorted(values)), flush=True)
        for log in ("/data/log/s6/php-fpm/current", "/data/log/nginx/error.log"):
            print("DIAG  %s:\n%s" % (log, sh(["tail", "-n", "15", log]).stdout), flush=True)
    return (m.group(1) if m else None), html


def test_destination(did):
    url, html = post_form(BASE + "/destinations/%s/edit" % did, suffix="/test")
    return html


def restic(did, *args):
    return sh(["poste-backup", "restic", did] + list(args))


def snapshots(did, job=None):
    args = ["snapshots", "--json"] + (["--tag", "job:" + job] if job else [])
    r = restic(did, *args)
    try:
        return json.loads(r.stdout)
    except ValueError:
        return None


# ===================================================================== install
print("--- backup: installation")
url, html = ADMIN.get("/admin/")
menu = open("/opt/admin/templates/_menu.html.twig").read()
check("menu entry for super admins", "/admin/backup/" in html and menu.count("route starts with 'poste_backup'") == 1, url)
perms = {p: owner(p) for p in ("/data/var/backup", "/data/var/backup/settings", "/data/var/backup/settings/queue",
                               "/data/var/backup/settings/ssh", "/data/var/backup/engine", "/data/var/backup/settings/restic.key")}
check("folders and key: all mail's (the engine runs as mail); key 0600",
      perms == {"/data/var/backup": "mail:mail 750", "/data/var/backup/settings": "mail:mail 750",
                "/data/var/backup/settings/queue": "mail:mail 750", "/data/var/backup/settings/ssh": "mail:mail 700",
                "/data/var/backup/engine": "mail:mail 750", "/data/var/backup/settings/restic.key": "mail:mail 600"}, perms)
key = open("/data/var/backup/settings/restic.key").read().strip()
check("encryption key made at the first start (48 random bytes, base64)", len(key) == 64 and re.match(r"^[A-Za-z0-9+/]+$", key) is not None, len(key))
check("cron runs the engine every minute, as mail", "* * * * * mail /usr/local/bin/poste-backup tick" in open("/etc/cron.d/poste-backup").read(), "cron.d")
versions = sh(["restic", "version"]).stdout.split()[:2] + sh(["rclone", "version"]).stdout.split()[:2]
check("restic and rclone installed", versions[0] == "restic" and versions[2] == "rclone", " ".join(versions))
unit = sh(["php", "/tests/backup_unit.php"])
check("unit checks of schedule and validation", unit.returncode == 0 and unit.stdout.count("PASS") >= 58,
      "%d PASS, exit %d" % (unit.stdout.count("PASS"), unit.returncode))
if unit.returncode:
    print("\n".join(l for l in unit.stdout.splitlines() if l.startswith("FAIL")))
for page in ("/", "/destinations", "/jobs", "/key", "/destinations/new", "/jobs/new"):
    url, html = ADMIN.get(BASE + page)
    # 200 and still on that page (not sent back to the login page)
    check("page %s" % page, ADMIN.status == 200 and url.rstrip("/").endswith((BASE + page).rstrip("/")), (ADMIN.status, url))

# ===================================================================== destinations
print("--- backup: destinations")
before = json.dumps(config())
post_form(BASE + "/destinations/new", {"name": "x", "type": "local", "path": "/backup/local"}, drop=("_token",))
check("a destination without the CSRF token is refused (nothing saved)", json.dumps(config()) == before, "config unchanged")

for what, values, msg in (
        ("a folder on /data", {"type": "local", "path": "/data/x"}, "absolute path under"),
        ("a folder with ..", {"type": "local", "path": "/backup/../data"}, "no &quot;.&quot; or &quot;..&quot;"),
        ("an SMB server with a ;", {"type": "smb", "host": "h;id", "share": "s", "user": "u", "password": "p"}, "host name or an IP"),
        ("an SFTP folder like an option", {"type": "sftp", "host": "h", "port": "22", "user": "u", "auth": "password", "password": "p", "folder": "-oProxyCommand=x"}, "letters, digits")):
    did, html = new_destination(dict({"name": "bad"}, **values))
    check("refused with a message: %s" % what, did is None and msg in html, re.findall(r'<li>([^<]*)</li>', html)[:2])
check("nothing saved by the refused forms", json.dumps(config()) == before, "config unchanged")

LOCAL, html = new_destination({"name": "Local volume", "type": "local", "path": "/backup/local"})
html = test_destination(LOCAL)
check("local volume (a real mount): test ok", "mounted volume" in html and "text-success" in html, re.findall(r"<li>([^<]*)</li>", html)[:3])

os.makedirs("/backup/notmounted", exist_ok=True)
NOTMOUNTED, html = new_destination({"name": "Not mounted", "type": "local", "path": "/backup/notmounted"})
html = test_destination(NOTMOUNTED)
check("a folder that is not a mounted volume: test refuses it", "not a mounted volume" in html, re.findall(r"<li>([^<]*)</li>", html)[:2])

SMB, html = new_destination({"name": "Samba", "type": "smb", "host": B["DEST_HOST"], "share": "backup", "folder": "poste",
                             "user": "bkuser", "password": B["DEST_PASSWORD"], "domain": ""})
html = test_destination(SMB)
check("SMB: test ok (reachable, free space reported, write test)", "write test: ok" in html and re.search(r"free space: [0-9.]+ [KMGT]B", html),
      re.findall(r"<li>([^<]*)</li>", html)[:4])
check("the password is never sent back to the browser", B["DEST_PASSWORD"] not in html, "edit page")


def human(b):
    """Panel::size in Python (1024 units, one decimal, "3" not "3.0")."""
    for u, n in (("TB", 1 << 40), ("GB", 1 << 30), ("MB", 1 << 20), ("KB", 1 << 10)):
        if b >= n:
            v = str(round(b / n, 1))
            return (v[:-2] if v.endswith(".0") else v) + " " + u
    return "%d B" % b


SMB_SIZE, SMB_AVAIL = int(B["DEST_SMB_SIZE"]), int(B["DEST_SMB_AVAIL"])
check("SMB: the size of the share is the real one (df on the server; rclone 1.60 would say half)",
      ("of %s</li>" % human(SMB_SIZE)) in html, (human(SMB_SIZE), re.findall(r"free space: [^<]*", html)))

SMBBAD, html = new_destination({"name": "Samba, wrong password", "type": "smb", "host": B["DEST_HOST"], "share": "backup",
                                "folder": "other", "user": "bkuser", "password": "wrong-" + B["DEST_PASSWORD"], "domain": ""})
check("SMB with a wrong password: saved (only the password is wrong)", SMBBAD is not None,
      (ADMIN.status, re.findall(r"<li>([^<]*)</li>", html)[:4], re.sub(r"\s+", " ", html)[:300] if SMBBAD is None else ""))
html = test_destination(SMBBAD)
check("SMB with a wrong password: test fails", "not reachable" in html and "text-danger" in html, re.findall(r"<li>([^<]*)</li>", html)[:2])

S3, html = new_destination({"name": "S3", "type": "s3", "endpoint": "http://%s:8080" % B["S3_HOST"], "region": "",
                            "bucket": "mail-backup", "folder": "poste", "access_key": "testkey", "secret_key": B["DEST_PASSWORD"]})
html = test_destination(S3)
check("S3: test ok (space not reported by S3)", "write test: ok" in html and "not reported by S3" in html, re.findall(r"<li>([^<]*)</li>", html)[:4])

SFTP, html = new_destination({"name": "SFTP", "type": "sftp", "host": B["DEST_HOST"], "port": "22", "user": "bkuser",
                              "auth": "password", "password": B["DEST_PASSWORD"], "folder": "/srv/sftp/poste"})
html = test_destination(SFTP)
fps = re.findall(r"<code>([A-Z0-9-]+ SHA256:[A-Za-z0-9+/]+)</code>", html)
real = sorted(l.split()[1] + " " + l.split()[2] for l in sh(["ssh-keyscan", B["DEST_HOST"]]).stdout.splitlines() if l and l[0] != "#")
check("SFTP, first test: the server's key fingerprints are shown, nothing trusted yet",
      len(fps) == len(real) and fps and not config()["destinations"][SFTP]["hostkeys"], fps)
url, html = post_form(BASE + "/destinations/%s/edit" % SFTP, suffix="/trust")
trusted = config()["destinations"][SFTP]["hostkeys"]
check("SFTP: confirmed keys are the server's real keys", sorted(trusted) == real, len(trusted))
html = test_destination(SFTP)
check("SFTP: test ok after the confirmation, free space reported", "write test: ok" in html and re.search(r"free space: [0-9.]+ [KMGT]B", html),
      re.findall(r"<li>([^<]*)</li>", html)[:4])

# a key login: the public key made by the admin is put on the server over SFTP
# with the password login (rclone, like an administrator would with scp)
SFTPKEY, html = new_destination({"name": "SFTP with a key", "type": "sftp", "host": B["DEST_HOST"], "port": "22", "user": "bkuser",
                                 "auth": "key", "folder": "/srv/sftp/poste-key"})
pub = re.search(r'<code class="bk-key">(ssh-ed25519 [A-Za-z0-9+/=]+ poste-backup)</code>', html)
check("SFTP with a key: a key pair is made and the public key shown", pub is not None and os.path.exists("/data/var/backup/settings/ssh/" + SFTPKEY),
      pub.group(1)[:40] if pub else "no key")
if pub:
    ob = sh(["rclone", "obscure", "-"], input=B["DEST_PASSWORD"] + "\n").stdout.strip()
    renv = dict(os.environ, RCLONE_CONFIG="/usr/local/lib/poste-backup/rclone.conf", RCLONE_CONFIG_UP_TYPE="sftp", RCLONE_CONFIG_UP_HOST=B["DEST_HOST"],
                RCLONE_CONFIG_UP_USER="bkuser", RCLONE_CONFIG_UP_PASS=ob)
    subprocess.run(["rclone", "rcat", "UP:.ssh/authorized_keys"], input=pub.group(1) + "\n", text=True, env=renv, capture_output=True)
post_form(BASE + "/destinations/%s/edit" % SFTPKEY, suffix="/test")              # scan
post_form(BASE + "/destinations/%s/edit" % SFTPKEY, suffix="/trust")             # confirm
html = test_destination(SFTPKEY)
check("SFTP with a key: test ok", "write test: ok" in html, re.findall(r"<li>([^<]*)</li>", html)[:4])

# ---- the form's Test button: the values typed are tested, nothing is saved
TESTS = "/data/var/backup/settings/tests.json"


def check_form(values, did=None, drop=()):
    """POSTs the destination form (new or edit) to its Test address; (HTTP status, JSON)."""
    f, html = form(BASE + ("/destinations/%s/edit" % did if did else "/destinations/new"))
    data = {n: (v or "") for n, v, t in f["fields"] if n and not t.endswith(":off") and t not in ("submit", "button")}
    data.update(values)
    for n in drop:
        data.pop(n, None)
    url, body = ADMIN.post(BASE + ("/destinations/%s/check" % did if did else "/destinations/check"), data)
    try:
        return ADMIN.status, json.loads(body)
    except ValueError:
        return ADMIN.status, {"raw": body[:200]}


before, tests_before = json.dumps(config()), open(TESTS).read() if os.path.exists(TESTS) else ""
smb_new = {"name": "Not saved", "type": "smb", "host": B["DEST_HOST"], "share": "backup", "folder": "poste-check",
           "user": "bkuser", "password": B["DEST_PASSWORD"], "domain": ""}
code, r = check_form(smb_new)
check("Test before saving, SMB: ok, free space and write test", code == 200 and r.get("status") == "ok" and "write test: ok" in r.get("lines", [])
      and any(re.match(r"free space: [0-9.]+ [KMGT]B", l) for l in r.get("lines", [])), r)
check("Test before saving: nothing saved, nothing remembered", json.dumps(config()) == before
      and (open(TESTS).read() if os.path.exists(TESTS) else "") == tests_before, "config and tests unchanged")
code, r = check_form(dict(smb_new, password="wrong-" + B["DEST_PASSWORD"]))
check("Test before saving, wrong password: an error", r.get("status") == "error" and any("not reachable" in l for l in r.get("lines", [])), r)
code, r = check_form(smb_new, drop=("_token",))
check("Test before saving without the CSRF token: refused", code == 403 and r.get("status") == "error", (code, r))
code, r = check_form(dict(smb_new, host="h;id"))
check("Test before saving, an invalid server: refused with the message, nothing run",
      r.get("status") == "error" and any("host name or an IP" in l for l in r.get("lines", [])), r)
code, r = check_form({}, did=SMB)
check("Test of a saved destination's form, password left empty: the saved password is used", r.get("status") == "ok", r)
code, r = check_form({"host": "other." + B["DEST_HOST"]}, did=SMB)
check("...but not for another server (the saved password is not sent there)",
      r.get("status") == "error" and any("password" in l for l in r.get("lines", [])), r)
sftp_new = {"name": "SFTP not saved", "type": "sftp", "host": B["DEST_HOST"], "port": "22", "user": "bkuser",
            "auth": "password", "password": B["DEST_PASSWORD"], "folder": "/srv/sftp/poste-check"}
code, r = check_form(sftp_new)
check("Test before saving, SFTP: only the server's keys are read and shown (nothing sent)",
      r.get("status") == "keys" and sorted(k["key"] for k in r.get("keys", [])) == real and all("SHA256:" in k["fingerprint"] for k in r["keys"]),
      [k.get("fingerprint") for k in r.get("keys", [])])
shown = "\n".join(k["key"] for k in r.get("keys", []))
code, r = check_form(dict(sftp_new, hostkeys=shown))
check("Test before saving, SFTP with the keys confirmed in the form: ok", r.get("status") == "ok" and "write test: ok" in r.get("lines", []), r)
code, r = check_form(dict(sftp_new, auth="key"))
check("Test before saving, SFTP with a key login: save first (the key pair is made then)", r.get("status") == "save_first", r)
check("still nothing saved", json.dumps(config()) == before, "config unchanged")
SFTPFORM, html = new_destination(dict(sftp_new, name="SFTP confirmed in the form", folder="/srv/sftp/poste-form", hostkeys=shown))
check("SFTP saved with the keys confirmed in the form: those keys, test ok",
      SFTPFORM and sorted(config()["destinations"][SFTPFORM]["hostkeys"]) == real and "write test: ok" in test_destination(SFTPFORM),
      SFTPFORM)

# ===================================================================== jobs
print("--- backup: jobs and runs")
# something to find again: a message in alice's mailbox, a message for bob,
# DKIM key files of the test domain, redis' dump
for user, subject, body in ((E["ALICE"], "restore me", "body of the message to restore"), (E["BOB"], "for bob", "bob body")):
    sh(["doveadm", "save", "-u", user], input="From: x@example.net\r\nTo: %s\r\nSubject: %s\r\nMessage-ID: <%s@example.net>\r\n\r\n%s\r\n"
       % (user, subject, subject.replace(" ", "-"), body))
dom = "/data/domains/" + E["ALICE"].split("@")[1]
for f, text in (("private", "-----BEGIN PRIVATE KEY-----\ntest\n"), ("public", "test"), ("selector", "s20261011")):
    if not os.path.exists("%s/%s" % (dom, f)):
        open("%s/%s" % (dom, f), "w").write(text)
sh(["redis-cli", "-s", "/var/run/redis/redis.sock", "SAVE"])
# as on a server in use: the webmail's database in WAL mode, owned by www-data
sh(["sqlite3", "/data/roundcube/roundcube.db", "PRAGMA journal_mode=WAL;"], user="www-data")
# for the restores: a message in alice's Sent, one received in 2020 (IMAP APPEND with its date),
# a redirect (Poste.io makes a folder for it too) and a mailbox deleted after the backup
sh(["doveadm", "mailbox", "create", "-u", E["ALICE"], "Sent"])
sh(["doveadm", "save", "-u", E["ALICE"], "-m", "Sent"], input="From: %s\r\nTo: y@example.net\r\nSubject: sent by alice\r\nMessage-ID: <sent-by-alice@example.net>\r\n\r\nsent\r\n" % E["ALICE"])
import imaplib, ssl
_m = imaplib.IMAP4_SSL("localhost", 993, ssl_context=ssl._create_unverified_context())
_m.login(E["ALICE"], E["PA"])
_m.append("INBOX", None, imaplib.Time2Internaldate(time.mktime((2020, 1, 15, 10, 0, 0, 0, 0, -1))),
          b"From: Old Customer <old@customer.example>\r\nTo: alice\r\nSubject: old one\r\nMessage-ID: <old-one@example.net>\r\n\r\nold\r\n")
_m.logout()
for box in ("redir", "gone"):
    sh(["runuser", "-u", "mail", "--", "php", "/opt/admin/bin/console", "email:create", "-q", "%s@%s" % (box, E["ALICE"].split("@")[1]), "pw-" + box + "-x7Kq2", box])
sh(["sqlite3", "/data/users.db", "UPDATE users SET redirectOnly = 1, redirectTo = '%s' WHERE address = 'redir@%s';" % (E["ALICE"], E["ALICE"].split("@")[1])])
sh(["doveadm", "save", "-u", "gone@" + E["ALICE"].split("@")[1]], input="From: x@example.net\r\nTo: gone\r\nSubject: gone mail\r\nMessage-ID: <gone-mail@example.net>\r\n\r\ngone\r\n")
alice_msg = sh(["grep", "-rl", "restore-me@example.net", "%s/%s/Maildir" % (dom, E["ALICE"].split("@")[0])]).stdout.split("\n")[0]
check("test data in place (message, DKIM files, redis dump)", alice_msg != "" and os.path.exists("/data/redis/dump.rdb"), alice_msg[-50:])

sched = {"every": "month", "monthday": "28", "hour": "3", "minute": "17", "weekday": "1"}
before = json.dumps(config())
post_form(BASE + "/jobs/new", dict(sched, name="x", config="1", mail="all", **{"destinations[]": LOCAL}), drop=("_token",))
check("a job without the CSRF token is refused (nothing saved)", json.dumps(config()) == before, "config unchanged")
url, html = post_form(BASE + "/jobs/new", dict(sched, name="none", mail="none", keep_daily="7"), drop=("config",))
check("a job with nothing to back up is refused with a message", "nothing to back up" in html, re.findall(r"<li>([^<]*)</li>", html)[:2])


def new_job(name, dests, extra):
    data = dict(sched, name=name, enabled="1", keep_hourly="0", keep_daily="7", keep_weekly="0", keep_monthly="0")
    data.update(extra)
    f, html = form(BASE + "/jobs/new")
    fields = [(n, v) for n, v, t in f["fields"] if n and n not in data and not t.endswith(":off") and n not in ("destinations[]", "mailboxes[]", "run_now", "config")]
    body = fields + list(data.items()) + [("destinations[]", d) for d in dests] + [("mailboxes[]", m) for m in extra.get("_boxes", [])]
    body = [(k, v) for k, v in body if k != "_boxes"]
    import urllib.parse
    ADMIN._open(BASE + "/jobs/new", urllib.parse.urlencode(body).encode())
    return [i for i, j in config()["jobs"].items() if j["name"] == name]


WHOLE = new_job("Whole server", [LOCAL, SMB, S3, SFTP, SFTPKEY], {"config": "1", "mail": "all"})
check("job saved from the admin", len(WHOLE) == 1, WHOLE)
WHOLE = WHOLE[0] if WHOLE else None
url, html = post_form(BASE + "/jobs", suffix="/%s/run" % WHOLE)
check("Run now: the request is accepted (the engine takes it within a minute)", "Run requested" in html, url)
t0 = time.time()
deadline = time.time() + 900
while time.time() < deadline:
    engine_idle()
    sh(["poste-backup", "tick"])
    s = state().get("jobs", {}).get(WHOLE)
    if s and s.get("last_start", 0) >= int(t0) - 1 and not os.listdir("/data/var/backup/settings/queue"):
        break
    time.sleep(3)
s = state().get("jobs", {}).get(WHOLE, {})
results = {d: r["status"] for d, r in s.get("destinations", {}).items()}
check("the queued run went to all five destinations, each ok", s.get("status") == "ok" and len(results) == 5 and set(results.values()) == {"ok"}, results)
for did, name in ((LOCAL, "local"), (SMB, "SMB"), (S3, "S3"), (SFTP, "SFTP"), (SFTPKEY, "SFTP with a key")):
    snaps = snapshots(did, WHOLE)
    check("snapshot on %s, with host 'poste' and the job's tag" % name,
          snaps is not None and len(snaps) == 1 and snaps[0]["hostname"] == "poste" and "job:" + WHOLE in snaps[0]["tags"],
          [(x["short_id"], x["hostname"], x["tags"]) for x in (snaps or [])])
hist = [json.loads(l) for l in open("/data/var/backup/engine/history.jsonl")]
check("one history line per destination of the run", len([h for h in hist if h["job"] == WHOLE]) == 5, len(hist))

ls = restic(S3, "ls", "latest", "--tag", "job:" + WHOLE).stdout.splitlines()
names = set(ls)
expect = ["/data/var/backup/engine/staging/users.db", "/data/var/backup/engine/staging/dav.db",
          "/data/var/backup/engine/staging/roundcube.db", "/data/redis/dump.rdb",
          dom + "/private", dom + "/selector", alice_msg]
check("in the snapshot: database copies, redis, DKIM keys, the mail", all(e in names for e in expect),
      [e for e in expect if e not in names] or "all there")
check("NOT in the snapshot: the encryption key and the settings with the passwords",
      not any("restic.key" in l or l.startswith("/data/var/backup/settings") for l in ls), len(ls))

# restore: a lost message comes back identical
saved = open(alice_msg, "rb").read()
os.remove(alice_msg)
shutil.rmtree("/tmp/restore", ignore_errors=True)
r = restic(SMB, "restore", "latest", "--tag", "job:" + WHOLE, "--target", "/tmp/restore", "--include", alice_msg)
back = "/tmp/restore" + alice_msg
check("restore from SMB: the deleted message comes back identical", os.path.exists(back) and open(back, "rb").read() == saved,
      r.stderr.strip()[-120:] or "restored")
os.makedirs(os.path.dirname(alice_msg), exist_ok=True)
open(alice_msg, "wb").write(saved)

# the restore procedure of docs/en/docs/backup.md, as written there: the mailbox restored
# into a separate folder, then its messages put in a folder "Restored" of the mailbox
alice_home = "%s/%s" % (dom, E["ALICE"].split("@")[0])
shutil.rmtree("/tmp/restore", ignore_errors=True)
restic(LOCAL, "restore", "latest", "--tag", "job:" + WHOLE, "--target", "/tmp/restore", "--include", alice_home)
proc = sh(["sh", "-c", 'H="$1"; U="$2"; mkdir -p "$H/Maildir/.Restored/cur" "$H/Maildir/.Restored/new" "$H/Maildir/.Restored/tmp" && '
           'cp -a "/tmp/restore$H/Maildir/cur/." "$H/Maildir/.Restored/cur/" && chown -R mail:mail "$H/Maildir/.Restored" && '
           'doveadm force-resync -u "$U" "*" && doveadm mailbox subscribe -u "$U" Restored', "sh", alice_home, E["ALICE"]])
found = sh(["doveadm", "search", "-u", E["ALICE"], "mailbox", "Restored", "header", "Message-ID", "restore-me@example.net"]).stdout.strip()
subs = sh(["doveadm", "mailbox", "list", "-s", "-u", E["ALICE"]]).stdout.split()
check("the documented restore procedure: folder Restored, subscribed, with the message",
      proc.returncode == 0 and found != "" and "Restored" in subs, (proc.returncode, proc.stderr.strip()[-120:], found[:60], subs))
shutil.rmtree("/tmp/restore2", ignore_errors=True)
restic(SFTPKEY, "restore", "latest", "--tag", "job:" + WHOLE, "--target", "/tmp/restore2", "--include", "/data/var/backup/engine/staging/users.db")
db = "/tmp/restore2/data/var/backup/engine/staging/users.db"
q = sh(["sqlite3", db, "PRAGMA integrity_check; SELECT count(*) FROM users WHERE address = '%s';" % E["ALICE"]]).stdout.split()
check("restore of the accounts database from SFTP (key login): intact, with the mailboxes", q == ["ok", "1"], q)

# a job for one mailbox, keep 1 per hour: three runs leave one snapshot with bob only
BOB = new_job("Bob only", [LOCAL], {"mail": "selected", "keep_hourly": "1", "keep_daily": "0", "_boxes": [E["BOB"]]})
BOB = BOB[0] if BOB else None
outs = [run_job(BOB).stdout.strip() for _ in range(3)]
snaps = snapshots(LOCAL, BOB)
check("selected mailbox, keep 1 per hour: 3 runs, 1 snapshot left", snaps is not None and len(snaps) == 1, (outs, len(snaps or [])))
ls = restic(LOCAL, "ls", "latest", "--tag", "job:" + BOB).stdout
bob_local, alice_local = E["BOB"].split("@")[0], E["ALICE"].split("@")[0]
check("that snapshot has bob's mailbox and nothing of alice's", "/%s/Maildir" % bob_local in ls and "/%s/" % alice_local not in ls, len(ls.splitlines()))
check("the other job's snapshot on the same destination is untouched", len(snapshots(LOCAL, WHOLE) or []) == 1, "keep rules per job")

# a file of the configuration that mail cannot read (like rspamd's cached maps
# in a server in use): left out on purpose, the job stays "ok", the log says it
unreadable = "/data/var/rspamd/0123456789abcdef0123456789abcdef01234567.map"
os.makedirs("/data/var/rspamd", exist_ok=True)
open(unreadable, "w").write("cached map\n")
os.chmod(unreadable, 0o600)

# one bad destination does not stop the others
MIXED = new_job("Mixed", [d for d in (SMBBAD, NOTMOUNTED, S3) if d], {"config": "1", "mail": "none"})
MIXED = MIXED[0] if MIXED else "000000000000"
run_job(MIXED)
s = state()["jobs"].get(MIXED, {})
res = {d: (r["status"], r["error"]) for d, r in s.get("destinations", {}).items()}
check("wrong password on SMB: an error for that destination, readable (no internal names)",
      res.get(SMBBAD, ("",))[0] == "error" and "logon is invalid" in (res.get(SMBBAD, ("", ""))[1] or "")
      and not re.search(r"\bD[0-9A-F]{12}:|^\d{4}/", res.get(SMBBAD, ("", ""))[1] or ""), res.get(SMBBAD))
check("not mounted folder: an error, and nothing written there", res.get(NOTMOUNTED, ("",))[0] == "error"
      and "not a mounted volume" in (res.get(NOTMOUNTED, ("", ""))[1] or "") and os.listdir("/backup/notmounted") == [], res.get(NOTMOUNTED))
check("the third destination of the same job still gets its backup, ok (an unreadable cached map is left out, not a warning)",
      res.get(S3, ("",))[0] == "ok", res.get(S3))
log = open("/data/var/backup/engine/logs/" + s["log"]).read() if s.get("log") else ""
ls = restic(S3, "ls", "latest", "--tag", "job:" + MIXED).stdout
check("the log says what was left out; the map is not in the snapshot, the rest of rspamd's folder is",
      "not readable by mail: left out" in log and unreadable not in ls and "/data/var/rspamd/" in ls, re.findall(r".*left out.*", log)[:1])
os.remove(unreadable)
shutil.rmtree("/tmp/restore3", ignore_errors=True)
restic(S3, "restore", "latest", "--tag", "job:" + MIXED, "--target", "/tmp/restore3", "--include", "/data/var/backup/engine/staging/roundcube.db")
q = sh(["sqlite3", "/tmp/restore3/data/var/backup/engine/staging/roundcube.db", "PRAGMA integrity_check;"]).stdout.strip()
check("the webmail's database (WAL, owned by www-data) is copied and restored intact", q == "ok", q)
url, html = ADMIN.get(BASE + "/")
check("the dashboard lists the problems", "problems" in html and "Samba, wrong password" in html, url)

# a folder that holds something else is never used as a repository
ob = sh(["rclone", "obscure", "-"], input=B["DEST_PASSWORD"] + "\n").stdout.strip()
renv = dict(os.environ, RCLONE_CONFIG="/usr/local/lib/poste-backup/rclone.conf", RCLONE_CONFIG_SM_TYPE="smb", RCLONE_CONFIG_SM_HOST=B["DEST_HOST"],
            RCLONE_CONFIG_SM_USER="bkuser", RCLONE_CONFIG_SM_PASS=ob)
subprocess.run(["rclone", "rcat", "SM:backup/foreign/somebody-elses.txt"], input="keep me\n", text=True, env=renv, capture_output=True)
FOREIGN, html = new_destination({"name": "Foreign folder", "type": "smb", "host": B["DEST_HOST"], "share": "backup", "folder": "foreign",
                                 "user": "bkuser", "password": B["DEST_PASSWORD"], "domain": ""})
html = test_destination(FOREIGN)
check("a non-empty folder that is not a repository: the test refuses it", "not empty" in html, re.findall(r"<li>([^<]*)</li>", html)[:2])

# ===================================================================== key
print("--- backup: key")
post_form(BASE + "/key", drop=("_token",))
check("key download without the CSRF token: refused", not os.path.exists("/data/var/backup/settings/key-downloaded"), "no download recorded")
f, html = form(BASE + "/key")
url, body = ADMIN.post(f["action"], {n: v or "" for n, v, t in f["fields"] if n})
check("key download: the key, with instructions; the time is recorded", key in body and "Without this key" in body
      and os.path.exists("/data/var/backup/settings/key-downloaded"), ADMIN.status)

# ===================================================================== hardening
print("--- backup: hardening")
# settings changed behind the admin: invalid entries are ignored, nothing is run
good = json.load(open(CONFIG))
evil = json.loads(json.dumps(good))
evil["destinations"]["eeeeeeeeeeee"] = {"name": "evil", "type": "smb", "host": "h; touch /tmp/backup-pwned", "share": "s", "folder": "",
                                        "user": "u", "password": "p", "domain": ""}
evil["destinations"]["../../etc/x"] = {"name": "evil2", "type": "local", "path": "/backup/local"}
evil["jobs"][WHOLE]["destinations"].append("eeeeeeeeeeee")
sh(["sh", "-c", "cat > %s" % CONFIG], user="mail", input=json.dumps(evil))
engine_idle()
sh(["poste-backup", "tick"])
errs = state().get("config_errors", [])
check("tampered settings: the bad entries are reported and left out", any("eeeeeeeeeeee" in e for e in errs) and any("invalid id" in e for e in errs), errs)
check("tampered settings: nothing they contain is ever run", not os.path.exists("/tmp/backup-pwned"), "no /tmp/backup-pwned")
sh(["sh", "-c", "cat > %s" % CONFIG], user="mail", input=json.dumps(good))

# the engine runs as mail: what it writes is mail's, and root hands over at once
st = owner(STATE)
check("the engine's files belong to mail (it never runs as root)", st.startswith("mail:mail"), st)
r = sh(["sh", "-c", "poste-backup status >/dev/null; echo $?"])
check("poste-backup started as root switches to mail and works", r.stdout.strip() == "0", r.stdout.strip())

# a mailbox home changed in users.db (mail can write it) never leads outside /data/domains
bob_home = sh(["sqlite3", "/data/users.db", "SELECT home FROM users WHERE address = '%s';" % E["BOB"]]).stdout.strip()
os.symlink("/", "%s/evil-link" % "/data/domains")
for what, home in (("a home with ..", "/data/domains/../.."), ("a home through a symbolic link", "/data/domains/evil-link/root")):
    sh(["sqlite3", "/data/users.db", "UPDATE users SET home = '%s' WHERE address = '%s';" % (home, E["BOB"])])
    r = run_job(BOB)
    s = state()["jobs"].get(BOB, {})
    ls = restic(LOCAL, "ls", "latest", "--tag", "job:" + BOB).stdout
    check("%s: refused (no such mailbox), nothing outside /data/domains backed up" % what,
          s.get("status") == "error" and "none of the selected mailboxes exists" in json.dumps(s)
          and "/etc/" not in ls and "/root/" not in ls, (s.get("status"), json.dumps(s)[-120:]))
sh(["sqlite3", "/data/users.db", "UPDATE users SET home = '%s' WHERE address = '%s';" % (bob_home, E["BOB"])])
os.remove("/data/domains/evil-link")

# a saved password is never reused for another server
url, html = post_form(BASE + "/destinations/%s/edit" % SMB, {"host": "192.0.2.1", "password": ""})
check("changing the server with the password left empty: the saved password is not reused",
      "password: 1 to 256 characters" in html and config()["destinations"][SMB]["host"] == B["DEST_HOST"], re.findall(r"<li>([^<]*)</li>", html)[:2])

# /data belongs to mail: a /data/var that is not root's makes the start script do nothing
sh(["sh", "-c", "mv /data/var /data/var.real && mkdir /data/var && chown mail:mail /data/var"])
r = sh(["sh", "/etc/cont-init.d/90-backup.sh"])
created = os.path.exists("/data/var/backup")
url, html = ADMIN.get(BASE + "/")
shown = "could not be prepared" in html
sh(["sh", "-c", "rm -r /data/var && mv /data/var.real /data/var"])
check("start script: /data/var not owned by root, nothing done, the reason shown in the admin",
      "nothing done" in r.stderr and not created and shown, r.stderr.strip())
r = sh(["sh", "/etc/cont-init.d/90-backup.sh"])
check("start script, /data/var back to normal: no error left", r.returncode == 0 and not os.path.exists("/run/poste-backup.start-error"), r.stderr.strip())
k = "/data/var/backup/settings/restic.key"
sh(["sh", "-c", "mv %s %s.real && ln -s %s.real %s" % (k, k, k, k)], user="mail")
r = run_job(BOB)
s = state()["jobs"].get(BOB, {})
check("engine: a key that is a symbolic link is refused", "key is missing" in json.dumps(s.get("destinations", {})), s.get("status"))
sh(["sh", "-c", "rm %s && mv %s.real %s" % (k, k, k)], user="mail")

# ===================================================================== space, logs, live pages, languages
print("--- backup: space, logs, live pages, languages")
sp = state().get("destinations", {})
check("space measured after the backups, on every destination",
      all(isinstance(sp.get(d, {}).get("used"), int) and sp[d]["used"] > 0 for d in (LOCAL, SMB, S3, SFTP, SFTPKEY)),
      {k: v.get("used") for k, v in sp.items()})
check("SMB: total and free space after a backup are the share's real ones (df on the server)",
      sp.get(SMB, {}).get("total") == SMB_SIZE and abs((sp.get(SMB, {}).get("free") or 0) - SMB_AVAIL) < SMB_SIZE * 0.02,
      (sp.get(SMB), SMB_SIZE, SMB_AVAIL))
check("free space where the server reports it, not on S3",
      all(isinstance(sp.get(d, {}).get("free"), int) for d in (LOCAL, SMB, SFTP)) and sp.get(S3, {}).get("free") is None,
      {k: v.get("free") for k, v in sp.items()})
url, html = ADMIN.get(BASE + "/")
check("dashboard: the space on the destinations", "Space on the destinations" in html and "used by the backups" in html, url)
url, html = ADMIN.get(BASE + "/destinations")
check("destinations page: the space used by the backups", "used by the backups" in html, url)

url, html = ADMIN.get(BASE + "/logs")
names = re.findall(r"/admin/backup/logs/(\d{8}-\d{6}-[a-f0-9]{12}\.log)", html)
check("logs page: the runs, with a link to the log of each", len(set(names)) >= 2 and "Whole server" in html and "Bob only" in html, len(names))
url, page = ADMIN.get(BASE + "/logs/" + names[0]) if names else ("", "")
check("a log opens, with what the engine wrote", ADMIN.status == 200 and "job finished" in page, url)
url, html = ADMIN.get(BASE + "/jobs")
check("jobs page: a link to the log of the last run", "/admin/backup/logs/" in html, url)
codes = []
for bad in ("../../etc/passwd", "passwd", "20261011-142901-0a1b2c3d4e5f.log"):
    url, page = ADMIN.get(BASE + "/logs/" + bad)
    codes.append((bad, ADMIN.status, "root:x:" in page))
link = "/data/var/backup/engine/logs/20990101-000000-0a1b2c3d4e5f.log"
os.symlink("/etc/passwd", link)
url, page = ADMIN.get(BASE + "/logs/" + os.path.basename(link))
codes.append(("a link to /etc/passwd", ADMIN.status, "root:x:" in page))
os.remove(link)
check("a log name that is not a log, does not exist or is a link: not found, nothing shown",
      all(c == 404 and not shown for _, c, shown in codes), codes)

# a mail file the backup may not read (root's, 0600): the run says so, as a warning
bob_cur = "%s/%s/Maildir/cur" % (dom, bob_local)
locked = bob_cur + "/1700000000.M1P1.mail.example.test,S=12:2,S"
open(locked, "w").write("locked file\n")
os.chmod(locked, 0o600)
run_job(BOB)
r = state()["jobs"].get(BOB, {}).get("destinations", {}).get(LOCAL, {})
check("a file the backup cannot read: warning, with how many (from restic's error lines)",
      r.get("status") == "warning" and r.get("error") == "1 file(s) could not be read (see the log)", r)
os.remove(locked)

# while a job runs, the open pages read its progress
big = bob_cur + "/1700000001.M2P2.mail.example.test,S=209715200:2,S"
with open(big, "wb") as f:
    for _ in range(200):
        f.write(os.urandom(1048576))
shutil.chown(big, "mail", "mail")
url, body = ADMIN.get(BASE + "/status")
sig_before = json.loads(body).get("signature")
seen = []
for _ in range(3):
    engine_idle()
    p = subprocess.Popen(["poste-backup", "run", BOB], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    while p.poll() is None:
        url, body = ADMIN.get(BASE + "/status")
        try:
            j = json.loads(body)
        except ValueError:
            j = {}
        if j.get("running"):
            seen.append(j["running"]["detail"])
        time.sleep(0.2)
    if "busy" not in p.stdout.read():
        break
os.remove(big)
url, body = ADMIN.get(BASE + "/status")
check("while a job runs, the status of the open pages has its progress (percent, sizes, files)",
      any(re.search(r"\d+(\.\d)?% · .* of .* · \d+ of \d+ files", d) for d in seen), (len(seen), seen[:2], seen[-2:]))
check("when the job ends, the status changes (the open pages reload)", json.loads(body).get("signature") != sig_before and not json.loads(body).get("running"), body[:120])

# the same messages in Czech (the admin follows the browser's language)
CS = Panel(E["ALICE"], E["PA"], lang="cs")
url, html = CS.get(BASE + "/destinations/new")
f = [x for x in Panel.parse(html).forms if x["method"].lower() == "post"][0]
data = {n: (v or "") for n, v, t in f["fields"] if n and not t.endswith(":off") and t not in ("submit", "button")}
data.update({"name": "x", "type": "smb", "host": "h;id", "share": "s", "user": "u", "password": "p"})
url, body = CS.post(BASE + "/destinations/check", data)
try:
    lines = json.loads(body).get("lines", [])
except ValueError:
    lines = [body[:100]]
check("in Czech: a message of the validation is translated", lines == ["server: název hostitele nebo adresa IP"], lines)
url, html = CS.get(BASE + "/logs")
check("in Czech: the logs page", "Záznamy záloh" in html, url)
url, html = CS.get(BASE + "/jobs")
check("in Czech: the jobs page", "Úlohy zálohování" in html, url)

# SFTP with a key: the command to run on the server, ready to copy
url, html = ADMIN.get(BASE + "/destinations/%s/edit" % SFTPKEY)
check("SFTP with a key: the command for the server, with the key in it",
      pub is not None and html.count(pub.group(1)) >= 2 and "chmod 600 ~/.ssh/authorized_keys" in html, url)

# the time zone of the container (its TZ, kept by s6): the admin shows the times
# of the jobs in it, not in UTC (Poste.io's admin has no time zone of its own).
# A zone far from UTC: if the page used UTC, the hour would be another one.
TZF = "/var/run/s6/container_environment/TZ"
old_tz = open(TZF).read() if os.path.exists(TZF) else None
open(TZF, "w").write("Pacific/Kiritimati")
url, html = ADMIN.get(BASE + "/jobs")
nexts = re.findall(r"next: (\d{4}-\d{2}-\d{2} \d{2}:\d{2})", html)
if old_tz is None:
    os.remove(TZF)
else:
    open(TZF, "w").write(old_tz)
check("the times of the jobs are shown in the container's time zone (the hour set, 03:17)",
      "Pacific/Kiritimati" in html and nexts and all(n.endswith("03:17") for n in nexts), nexts)

# keeping the logs: the days set in the admin, after each run
before = json.dumps(config())
post_form(BASE + "/logs", {"log_days": "30", "history_days": "100"}, suffix="/settings/logs", drop=("_token",))
check("log settings without the CSRF token: refused (nothing saved)", json.dumps(config()) == before, "config unchanged")
url, html = post_form(BASE + "/logs", {"log_days": "0", "history_days": "100"}, suffix="/settings/logs")
check("log days out of range: refused with a message, nothing saved",
      json.dumps(config()) == before and "log days: a whole number from 1 to 3650" in html, config().get("settings"))
url, html = post_form(BASE + "/logs", {"log_days": "30", "history_days": "100"}, suffix="/settings/logs")
check("log settings saved", config()["settings"]["log_days"] == 30 and config()["settings"]["history_days"] == 100, config().get("settings"))
LOGS = "/data/var/backup/engine/logs"
old_log = LOGS + "/20200101-000000-0a1b2c3d4e5f.log"
sh(["sh", "-c", 'echo old > "$1" && touch -d "40 days ago" "$1"', "sh", old_log], user="mail")
recent_logs = sorted(f for f in os.listdir(LOGS) if re.match(r"^\d{8}-\d{6}-[a-f0-9]{12}\.log$", f) and f != os.path.basename(old_log))
HIST = "/data/var/backup/engine/history.jsonl"
old_line = json.dumps({"t": int(time.time()) - 200 * 86400, "start": 0, "job": "0a1b2c3d4e5f", "dest": "0a1b2c3d4e5f", "reason": "schedule", "status": "ok"})
sh(["sh", "-c", 'H="$1"; { echo "$2"; cat "$H"; } > "$H.new" && mv "$H.new" "$H" && chmod 640 "$H"', "sh", HIST, old_line], user="mail")
lines_before = len(open(HIST).read().splitlines())
run_job(BOB)
left = os.listdir(LOGS)
hist = [json.loads(l) for l in open(HIST).read().splitlines()]
check("after a run: a log older than the days set is removed, the recent ones stay",
      os.path.basename(old_log) not in left and all(f in left for f in recent_logs[-5:]), (len(recent_logs), len(left)))
check("after a run: the history keeps only its days (the old line is gone, the recent ones and the new run stay)",
      all(h["t"] >= time.time() - 100 * 86400 for h in hist) and len(hist) == lines_before - 1 + 1, (lines_before, len(hist)))
post_form(BASE + "/logs", {"log_days": "90", "history_days": "400"}, suffix="/settings/logs")

# the time zone chosen in the admin wins, and is refused when it does not exist
before = json.dumps(config())
post_form(BASE + "/jobs", {"timezone": "Pacific/Kiritimati"}, suffix="/settings/timezone", drop=("_token",))
check("time zone without the CSRF token: refused (nothing saved)", json.dumps(config()) == before, "config unchanged")
url, html = post_form(BASE + "/jobs", {"timezone": "Mars/Olympus"}, suffix="/settings/timezone")
check("a time zone that does not exist: refused with a message", config()["settings"]["timezone"] == "" and "time zone: not valid" in html, config().get("settings"))
url, html = post_form(BASE + "/jobs", {"timezone": "Pacific/Kiritimati"}, suffix="/settings/timezone")
nexts = re.findall(r"next: (\d{4}-\d{2}-\d{2} \d{2}:\d{2})", html)
check("time zone chosen in the admin: saved, and the times of the jobs shown in it (the hour set, 03:17)",
      config()["settings"]["timezone"] == "Pacific/Kiritimati" and "Pacific/Kiritimati" in html and nexts and all(n.endswith("03:17") for n in nexts), nexts)
z = sh(["php", "-r", 'require "/usr/local/lib/poste-backup/lib/Store.php"; foreach (["Config","Schedule","Proc","Destination","Engine"] as $c) require "/usr/local/lib/poste-backup/lib/$c.php"; '
        '$r = new ReflectionProperty("PosteBackup\\Engine", "tz"); $r->setAccessible(true); $e = new PosteBackup\\Engine(); '
        '$m = new ReflectionMethod("PosteBackup\\Engine", "load"); $m->setAccessible(true); $m->invoke($e); echo $r->getValue($e)->getName();'], user="mail")
check("the engine uses the time zone chosen in the admin", z.stdout.strip() == "Pacific/Kiritimati", (z.stdout.strip(), z.stderr.strip()[-200:]))
post_form(BASE + "/jobs", {"timezone": ""}, suffix="/settings/timezone")
check("time zone back to the container's", config()["settings"]["timezone"] == "", config().get("settings"))

# ===================================================================== snapshots and restore
print("--- backup: snapshots and restore")
QUEUE = "/data/var/backup/settings/queue"


def queued_tasks():
    return sorted(f for f in os.listdir(QUEUE) if re.match(r"^\d{10}-(restore|forget)-[a-f0-9]{12}\.json$", f))


def task_ids():
    """The ids of the results of requests kept now (the state keeps the last 20: never counted)."""
    return {t.get("id") for t in state().get("tasks", [])}


def run_tasks(before, expect, timeout=600):
    """Lets the engine take the requests in the queue; returns the new results (by id, not by
    count). Verifications started by their schedule (cron runs the engine meanwhile) are left out."""
    def new():
        return [t for t in state().get("tasks", []) if t.get("id") not in before
                and not (t.get("type") == "verify" and t.get("why") == "schedule")]
    end = time.time() + timeout
    while time.time() < end:
        engine_idle()
        sh(["poste-backup", "tick"])
        if len(new()) >= expect and not queued_tasks():
            return new()
        time.sleep(2)
    return new()


def message_files(home):
    """Every message file of a mailbox (path -> md5), outside the restored folders."""
    out = {}
    for root, dirs, files in os.walk(home + "/Maildir"):
        if "/.Restored " in root + "/" or os.path.basename(root) not in ("cur", "new"):
            continue
        for f in files:
            out[os.path.join(root, f)] = hashlib.md5(open(os.path.join(root, f), "rb").read()).hexdigest()
    return out


def restored_folders(user):
    return [l for l in sh(["doveadm", "mailbox", "list", "-u", user]).stdout.split("\n") if l.startswith("Restored ")]


smb_snaps = sorted(snapshots(SMB, WHOLE) or [], key=lambda x: x["time"])
SNAP = smb_snaps[-1]["id"] if smb_snaps else "0" * 64
url, html = ADMIN.get(BASE + "/snapshots?dest=" + SMB)
check("snapshots page: the snapshots of the destination, with the job's name and what they hold",
      ADMIN.status == 200 and SNAP[:8] in html and "Whole server" in html and "configuration + all mail" in html, (ADMIN.status, SNAP[:8]))
url, html = ADMIN.get(BASE + "/snapshots?dest=" + SMBBAD)
check("a destination that cannot be read: the error is shown", ADMIN.status == 200 and "cannot read the snapshots" in html,
      re.findall(r'alert-danger">([^<]*)', html)[:1])
url, html = ADMIN.get(BASE + "/")
check("dashboard: a link to snapshots and restore", "/admin/backup/snapshots" in html, url)
check("the add-on's name in the menu and on its pages: Mailserver backup (Czech: Zálohy poštovního serveru)",
      html.count("Mailserver backup") >= 2 and "Zálohy poštovního serveru" in Panel(E["ALICE"], E["PA"], lang="cs").get(BASE + "/")[1],
      html.count("Mailserver backup"))

# the mailbox deleted after the backup: still in the snapshot, no longer on the server
DOMAIN = E["ALICE"].split("@")[1]
sh(["sqlite3", "/data/users.db", "DELETE FROM users WHERE address = 'gone@%s';" % DOMAIN])
url, html = ADMIN.get(BASE + "/snapshots/%s/%s/restore" % (SMB, SNAP))
rows = dict(re.findall(r"<tr><td>([^<]+@[^<]+?)(?:<span[^>]*>[^<]*</span>)?</td>\s*<td class=\"text-right\">([0-9,]+)</td>", html))
check("restore, step 1: the mailboxes of the snapshot that hold messages, with how many",
      int(rows.get(E["ALICE"], "0").replace(",", "")) >= 3 and E["BOB"] in rows and "gone@" + DOMAIN in rows, rows)
check("step 1: an empty folder (a redirect, a mailbox without mail) is not offered, it is counted in a line",
      "redir@" + DOMAIN not in rows and E["CAROL"] not in rows and "1 of them is a redirect" in html, re.findall(r"more address[^<]*", html)[:1])
check("step 1: the time of the snapshot is shown (in the time zone of the backups), not its id",
      re.search(r"Snapshot of \d{4}-\d\d-\d\d \d\d:\d\d on ", html) is not None, re.findall(r"Snapshot of [^<]*", html)[:1])
check("step 1: a mailbox no longer on this server is marked", re.search(r"gone@[^<]+<span class=\"bk-tag\">no longer on this server", html) is not None, "gone")
codes = []
for bad in ("/snapshots/%s/%s/restore" % (SMB, SNAP[:8]), "/snapshots/%s/%s/restore" % (SMB, SNAP.upper()),
            "/snapshots/%s/%s/restore" % ("eeeeeeeeeeee", SNAP), "/snapshots/%s/%s/server" % (SMB, "../" + SNAP[3:])):
    ADMIN.get(BASE + bad)
    codes.append(ADMIN.status)
check("a snapshot id that is not one, or an unknown destination: not found", codes == [404, 404, 404, 404], codes)


def restore_page(src, dest=None, snap=None):
    return BASE + "/snapshots/%s/%s/restore?source=%s" % (dest or SMB, snap or SNAP, urllib.parse.quote(src))


def restore_post(src, values, folders=None, dest=None, snap=None, drop=(), messages=None):
    """The form of step 2 as a browser sends it: the folders ticked (all those
    with messages, or the ones given), the other fields as on the page; with
    messages, the single messages ticked (what the page's script adds)."""
    path = restore_page(src, dest, snap)
    url, html = ADMIN.get(path)
    fs = [x for x in Panel.parse(html).forms if x["method"].lower() == "post"]
    if not fs:
        return url, html
    body = []
    for n, v, t in fs[0]["fields"]:
        if not n or t in ("submit", "button") or n in values or n in drop or t.endswith(":off"):
            continue
        if n == "folders[]" and folders is not None:
            continue
        body.append((n, v or ""))
    body += [("folders[]", x) for x in (folders or [])] + list(values.items())
    if messages is not None:
        body = [(n, v) for n, v in body if n != "what"] + [("what", "messages"), ("messages", "\r\n".join(messages))]
    return ADMIN._open(path, urllib.parse.urlencode(body).encode())


url, html = ADMIN.get(restore_page(E["ALICE"]))
f = Panel.parse(html).forms
fields = [x for x in f if x["method"].lower() == "post"][0]["fields"] if f else []
target = [v for n, v, t in fields if n == "target"]
folder_values = [v for n, v, t in fields if n == "folders[]"]
check("step 2: the folders of the mailbox with their messages (the inbox, Sent, ...), all ticked; the same mailbox to restore into",
      "" in folder_values and ".Sent" in folder_values and target == [E["ALICE"]] and 'value="Restored ' in html, (folder_values, target))
url, html = ADMIN.get(restore_page("gone@" + DOMAIN))
fields = [x for x in Panel.parse(html).forms if x["method"].lower() == "post"][0]["fields"]
check("step 2 for a mailbox no longer on the server: no mailbox chosen for the user", [v for n, v, t in fields if n == "target"] == [""]
      and "is not a mailbox of this server" in html, [v for n, v, t in fields if n == "target"])
url, html = ADMIN.get(restore_page("nobody@" + DOMAIN))
check("step 2 for a mailbox that is not in the snapshot: the message, no form", "is not in this snapshot" in html
      and not [x for x in Panel.parse(html).forms if x["method"].lower() == "post"], re.findall(r'alert-danger">([^<]*)', html)[:1])

before_q = queued_tasks()
restore_post(E["ALICE"], {"target": E["CAROL"]}, drop=("_token",))
refused = []
for what, src, values, folders, expect in (
        ("no mailbox to restore into", "gone@" + DOMAIN, {}, None, "choose the mailbox to restore into"),
        ("a target that is not a mailbox", E["ALICE"], {"target": "redir@" + DOMAIN}, None, "redir@%s not found" % DOMAIN),
        ("no folder", E["ALICE"], {"target": E["CAROL"]}, [], "choose at least one folder"),
        ("a folder that is not in the snapshot", E["ALICE"], {"target": E["CAROL"]}, [".Nope"], "a chosen folder is not in this snapshot"),
        ("a folder name with a dot", E["ALICE"], {"target": E["CAROL"], "name": "a.b"}, None, "without dots, slashes"),
        ("a parent folder that does not exist", E["ALICE"], {"target": E["CAROL"], "parent": "Nope"}, None, "the folder Nope is not in"),
        ("dates the wrong way round", E["ALICE"], {"target": E["CAROL"], "since": "2026-10-08", "until": "2026-10-07"}, None, "the first one is after")):
    url, html = restore_post(src, values, folders)
    refused.append((what, expect in html))
check("restore refused, with its message: " + ", ".join(w for w, ok in refused), all(ok for w, ok in refused), [w for w, ok in refused if not ok])
check("restore: without the CSRF token or with a refused value, nothing is asked of the engine", queued_tasks() == before_q, queued_tasks())

# into another mailbox: carol gets alice's mail in a new folder; nothing of carol's changes
alice_home = "%s/%s" % (dom, E["ALICE"].split("@")[0])
carol_home = sh(["sqlite3", "/data/users.db", "SELECT home FROM users WHERE address = '%s';" % E["CAROL"]]).stdout.strip()
# carol's own mail before: one message, so that "nothing changed" could fail
sh(["doveadm", "save", "-u", E["CAROL"]], input="From: x@example.net\r\nTo: %s\r\nSubject: carol's own\r\nMessage-ID: <carol-own@example.net>\r\n\r\nmine\r\n" % E["CAROL"])
carol_before, alice_before = message_files(carol_home), message_files(alice_home)
n0 = task_ids()
url, html = restore_post(E["ALICE"], {"target": E["CAROL"]})
check("restore requested from the admin (the engine does it within a minute)", "Restore requested" in html, url)
done = run_tasks(n0, 1)
r = done[0] if done else {}
folders = restored_folders(E["CAROL"])
check("restore into another mailbox: ok, with the folder and the number of messages",
      r.get("status") == "ok" and re.match(r'^\d+ message\(s\) restored into the folder "Restored \d{4}-\d\d-\d\d \d\d:\d\d" of ' + re.escape(E["CAROL"]) + '$',
                                           r.get("note") or "") is not None, (r.get("status"), r.get("note"), r.get("error")))
# message files only (Dovecot's index cache holds the headers too)
found = [l for l in sh(["grep", "-rl", "restore-me@example.net", carol_home + "/Maildir"]).stdout.split("\n")
         if "/.Restored " in l and re.search(r"/(cur|new)/[^/]+$", l)]
check("the message is in carol's folder Restored <time>.INBOX, identical to alice's", len(found) == 1 and ".INBOX/" in found[0]
      and open(found[0], "rb").read() == saved, found)
subs = sh(["doveadm", "mailbox", "list", "-s", "-u", E["CAROL"]]).stdout.split("\n")
check("the restored folders are subscribed (the mail programs show them)", any(f.endswith(".INBOX") for f in folders)
      and any(f.endswith(".Sent") for f in folders) and all(f in subs for f in folders if "." in f), (folders, subs))
after = message_files(carol_home)
check("nothing of carol's own mail changed", len(carol_before) >= 1 and all(after.get(p) == h for p, h in carol_before.items()), len(carol_before))
check("the restore folder of the engine is gone", not os.path.exists("/data/var/backup/engine/restore"), "removed")

# into the same mailbox, twice: the second folder gets " (2)"
n0 = task_ids()
restore_post(E["ALICE"], {"target": E["ALICE"]})
restore_post(E["ALICE"], {"target": E["ALICE"]})
done = run_tasks(n0, 2)
notes = [t.get("note") or t.get("error") for t in done]
counts = [int(n.split(" ")[0]) if n and n[0].isdigit() else -1 for n in notes]
check("restored twice into the same mailbox: two folders, the second with (2), the same number of messages in both",
      len(done) == 2 and all(t.get("status") == "ok" for t in done) and '(2)" of ' in (notes[1] if len(notes) > 1 else "")
      and counts[0] > 0 and counts[0] == counts[-1], notes)
after = message_files(alice_home)
check("nothing of alice's own mail changed", len(alice_before) >= 1 and all(after.get(p) == h for p, h in alice_before.items()), len(alice_before))
url, html = ADMIN.get(BASE + "/snapshots?dest=" + SMB)
check("snapshots page: the restores done, with their log", "Restore of %s into %s" % (E["ALICE"], E["CAROL"]) in html
      and "/admin/backup/logs/" in html, url)

# only one folder, into a new folder of my choice inside an existing folder of carol
sh(["doveadm", "mailbox", "create", "-u", E["CAROL"], "Archive"])
n0 = task_ids()
restore_post(E["ALICE"], {"target": E["CAROL"], "name": "Partial test", "parent": "Archive"}, folders=[".Sent"])
done = run_tasks(n0, 1)
r = done[0] if done else {}
part = [f for f in restored_folders(E["CAROL"])] + [l for l in sh(["doveadm", "mailbox", "list", "-u", E["CAROL"]]).stdout.split("\n") if l.startswith("Archive.Partial test")]
check("only the Sent folder, into Archive / Partial test of carol: that folder and its message, nothing else",
      r.get("note") == '1 message(s) restored into the folder "Archive / Partial test" of %s' % E["CAROL"]
      and "Archive.Partial test.Sent" in part and "Archive.Partial test.INBOX" not in part, (r.get("note"), r.get("error"), part))

# only some messages: by subject, by the date received, a text that starts with "-"
n0 = task_ids()
restore_post(E["ALICE"], {"target": E["CAROL"], "name": "By subject", "subject": "RESTORE me"})
restore_post(E["ALICE"], {"target": E["CAROL"], "name": "Until January 2020", "until": "2020-01-31"})
restore_post(E["ALICE"], {"target": E["CAROL"], "name": "Late January 2020", "since": "2020-01-16", "until": "2020-01-31"})
restore_post(E["ALICE"], {"target": E["CAROL"], "name": "Dash", "subject": "-s"})
done = run_tasks(n0, 4)
notes = [t.get("note") or t.get("error") for t in done]
old = [l for l in sh(["grep", "-rl", "old-one@example.net", carol_home + "/Maildir"]).stdout.split("\n") if "/.Until January 2020." in l and re.search(r"/(cur|new)/", l)]
check("filter by subject (case does not matter): only that message", len(notes) == 4 and notes[0].startswith("1 message(s) restored into the folder \"By subject\""), notes[:1])
check("filter by date received: the message of 15 January 2020 with 'until 31 January', identical", len(notes) == 4
      and notes[1].startswith("1 message(s)") and len(old) == 1, (notes[1:2], old))
check("a date range with no message, and a text that starts with '-' (never an option): nothing restored, no error",
      len(notes) == 4 and notes[2] == notes[3] == "no message matched the choice: nothing restored"
      and all(t.get("status") == "ok" for t in done), notes[2:])

# requests changed behind the admin: refused by the engine, nothing restored
n0 = task_ids()
good = {"dest": SMB, "snapshot": SNAP, "source": E["ALICE"], "target": E["CAROL"], "folders": [""], "name": "Tampered"}
for name, body in (("1000000001-restore-0a0a0a0a0a0a.json", dict(good, source="../../etc@example.test")),
                   ("1000000002-restore-0b0b0b0b0b0b.json", dict(good, folders=["../../../etc"])),
                   ("1000000003-restore-0c0c0c0c0c0c.json", dict(good, snapshot="f" * 64))):
    sh(["sh", "-c", 'cat > "$1"', "sh", QUEUE + "/" + name], user="mail", input=json.dumps(body))
done = run_tasks(n0, 3)
errs = [t.get("error") for t in done]
check("a request changed behind the admin is refused (a path as address or as folder); a snapshot that does not exist gives an error",
      len(done) == 3 and errs[0] == errs[1] == "the request is not valid" and (errs[2] or "").startswith("cannot read the snapshot"), errs)
check("nothing restored by them", not [f for f in restored_folders(E["CAROL"]) if f.startswith("Tampered")], restored_folders(E["CAROL"]))

# ===================================================================== single messages
print("--- backup: single messages (the list of the messages)")
INDEX = "/data/var/backup/engine/index.db"


def idx(sql):
    # as mail, like the engine: root would leave a journal file mail cannot write
    return sh(["sqlite3", "-separator", "|", INDEX, sql], user="mail").stdout.strip()


def messages_json(dest, snap, src, folder="*", q="", page=0):
    url, body = ADMIN.get(BASE + "/snapshots/%s/%s/messages?%s" % (dest, snap, urllib.parse.urlencode(
        {"source": src, "folder": folder, "q": q, "page": page})))
    try:
        return json.loads(body)
    except ValueError:
        return {"status": ADMIN.status, "body": body[:200]}


A = E["ALICE"]
alice_home = "%s/%s" % (dom, A.split("@")[0])
check("the list of the messages exists after a backup with mail, readable only by mail", os.path.exists(INDEX) and owner(INDEX) == "mail:mail 600",
      owner(INDEX) if os.path.exists(INDEX) else "missing")
whole_ls = restic(LOCAL, "ls", "latest", "--tag", "job:" + WHOLE).stdout.splitlines()
check("it is in the backup of the configuration (a consistent copy, like the databases), not as the live file",
      "/data/var/backup/engine/staging/index.db" in whole_ls and INDEX not in whole_ls, len(whole_ls))
# a subject and a sender encoded as e-mail headers are, a message to delete, then a job for alice's mail
for mid, frm, subj in (("t2c-encoded", "=?UTF-8?B?w4hyaWMgUsOpbXk=?= <e@example.org>", "=?UTF-8?Q?Fattura_n=C2=B0_12_=E2=80=93_ottobre?="),
                       ("t2c-deleted", "x@example.net", "to be deleted")):
    sh(["doveadm", "save", "-u", A], input="From: %s\r\nTo: %s\r\nSubject: %s\r\nMessage-ID: <%s@example.net>\r\n\r\nt2c\r\n" % (frm, A, subj, mid))
ALICEJOB = new_job("Alice mail", [LOCAL], {"mail": "selected", "keep_daily": "30", "_boxes": [A]})
ALICEJOB = ALICEJOB[0] if ALICEJOB else "000000000000"
run_job(ALICEJOB)
S1 = (sorted(snapshots(LOCAL, ALICEJOB) or [], key=lambda x: x["time"]) or [{"id": "0" * 64}])[-1]["id"]
row = idx("SELECT subject, sender, gone IS NULL FROM msg WHERE mailbox = '%s' AND subject LIKE 'Fattura%%';" % A)
check("subject and sender decoded (UTF-8 and quoted-printable, base64), the message there", row == "Fattura n° 12 – ottobre|Èric Rémy <e@example.org>|1", row)
files = set()
for root, dirs, fs in os.walk(alice_home + "/Maildir"):
    if os.path.basename(root) in ("cur", "new"):
        files |= {f.split(":")[0] for f in fs}
n_idx = idx("SELECT count(*) FROM msg WHERE mailbox = '%s' AND gone IS NULL;" % A)
check("every message of the mailbox is in the list, once (a copy in another folder is the same message)", n_idx == str(len(files)) and len(files) > 5,
      (n_idx, len(files)))
log = open("/data/var/backup/engine/logs/" + state()["jobs"][ALICEJOB]["log"]).read() if state()["jobs"].get(ALICEJOB, {}).get("log") else ""
check("the job's log says how the list was updated", re.search(r"list of the messages: 1 mailbox\(es\), \d+ new message\(s\), \d+ no longer there", log)
      is not None, re.findall(r"list of the messages.*", log)[:2])

# the admin's list of a snapshot's messages
r = messages_json(LOCAL, S1, A, q="fattura")
m = (r.get("rows") or [{}])[0]
check("the messages of a snapshot: a search finds the message, with folder, sender's name, subject, date, size",
      r.get("total") == 1 and m.get("folder") == "Inbox" and m.get("from") == "Èric Rémy" and m.get("sender") == "Èric Rémy <e@example.org>"
      and m.get("subject") == "Fattura n° 12 – ottobre" and re.match(r"^\d{4}-\d\d-\d\d \d\d:\d\d$", m.get("time", "")) is not None
      and m.get("id", "").startswith("cur/"), r)
r2, r3 = messages_json(LOCAL, S1, A, q="ÈRIC"), messages_json(LOCAL, S1, A, q="zzz-nothing")
check("the search: case does not matter, accented letters too; nothing found is 0", r2.get("total") == 1 and r3.get("total") == 0, (r2.get("total"), r3.get("total")))
r4 = messages_json(LOCAL, S1, A, folder=".Sent")
check("one folder only: Sent of that snapshot (the message moved later is not there yet)",
      r4.get("total", 0) >= 1 and all(x["folder"] == "Sent" for x in r4.get("rows", [])) and not [x for x in r4.get("rows", []) if x["subject"] == "Fattura n° 12 – ottobre"],
      [(x["folder"], x["subject"]) for x in r4.get("rows", [])][:3])
allr = messages_json(LOCAL, S1, A)
check("every message of the snapshot, newest first, one page", allr.get("total") == len(allr.get("rows", [])) > 5
      and [x["time"] for x in allr["rows"]] == sorted([x["time"] for x in allr["rows"]], reverse=True), allr.get("total"))
codes = []
for bad in ({"folder": "../x"}, {"folder": "Sent"}, {"q": "a\nb"}, {"page": -1}):
    url, body = ADMIN.get(BASE + "/snapshots/%s/%s/messages?%s" % (LOCAL, S1, urllib.parse.urlencode(dict({"source": A}, **bad))))
    codes.append(ADMIN.status)
ADMIN.get(BASE + "/snapshots/%s/%s/messages?source=%s" % ("eeeeeeeeeeee", S1, A))
codes.append(ADMIN.status)
check("the list refuses a folder that is a path, a text with a new line, a page below 0, an unknown destination", codes == [400, 400, 400, 400, 404], codes)
r6 = messages_json(LOCAL, "f" * 64, A)
check("a snapshot that is not there: an error, not an empty list", "cannot read the snapshot" in (r6.get("error") or "") and "rows" not in r6, r6)
# a message the list does not know (as one saved before it was kept): shown without details
sent_id = idx("SELECT guid FROM msg WHERE mailbox = '%s' AND subject = 'sent by alice';" % A)
idx("DELETE FROM msg WHERE mailbox = '%s' AND guid = '%s';" % (A, sent_id))
r5 = messages_json(LOCAL, S1, A, folder=".Sent")
check("a message the list does not know is still listed, without sender and subject", any(x["subject"] is None and x["from"] is None for x in r5.get("rows", [])),
      [(x["from"], x["subject"]) for x in r5.get("rows", [])])
url, html = ADMIN.get(restore_page(A, LOCAL, S1))
check("step 2: the choice between folders and single messages, the list of the messages", 'value="messages"' in html and "Single messages" in html
      and "/messages" in html and 'id="bk-mlist"' in html, url)

# two messages of many, into carol: only those two
# the inbox's copies only (earlier restores put copies of "old one" in alice's Restored folders)
ids = [x["id"] for x in allr.get("rows", []) if x["subject"] in ("Fattura n° 12 – ottobre", "old one") and x["id"].startswith("cur/")]
n0 = task_ids()
url, html = restore_post(A, {"target": E["CAROL"], "name": "Two messages"}, dest=LOCAL, snap=S1, messages=ids)
done = run_tasks(n0, 1)
r = done[0] if done else {}
got = sorted(re.findall(r"<([^>]+)>", sh(["doveadm", "fetch", "-u", E["CAROL"], "hdr.message-id", "mailbox", "Two messages.*", "all"]).stdout))
check("two messages chosen, restored into carol: exactly those two (in their folder), nothing else",
      len(ids) == 2 and r.get("note") == '2 message(s) restored into the folder "Two messages" of %s' % E["CAROL"]
      and got == ["old-one@example.net", "t2c-encoded@example.net"], (ids, r.get("note"), r.get("error"), got))
before_q = queued_tasks()
refused = []
for what, msgs, expect in (("none chosen", [], "choose at least one message"), ("more than 1000", ["cur/x%d" % i for i in range(1001)], "up to 1000 at a time"),
                           ("a path outside the Maildir", ["../../../etc/cur/passwd"], "message: not valid")):
    url, html = restore_post(A, {"target": E["CAROL"], "name": "Refused"}, dest=LOCAL, snap=S1, messages=msgs)
    refused.append((what, expect in html))
check("single messages refused, with their message: " + ", ".join(w for w, ok in refused), all(ok for w, ok in refused) and queued_tasks() == before_q,
      [w for w, ok in refused if not ok])
n0 = task_ids()
sh(["sh", "-c", 'cat > "$1"', "sh", QUEUE + "/1000000004-restore-0d0d0d0d0d0d.json"], user="mail",
   input=json.dumps({"dest": LOCAL, "snapshot": S1, "source": A, "target": E["CAROL"], "name": "Not there", "messages": ["cur/1.M1P1.nothere:2,S"]}))
done = run_tasks(n0, 1)
check("a message that is not in the snapshot: refused by the engine, nothing restored",
      [t.get("error") for t in done] == ["a chosen message is not in this snapshot"] and not [f for f in restored_folders(E["CAROL"]) if "Not there" in f]
      and "Not there" not in sh(["doveadm", "mailbox", "list", "-u", E["CAROL"]]).stdout, [t.get("error") for t in done])

CSL = Panel(E["ALICE"], E["PA"], lang="cs")
url, html = CSL.get(restore_page(A, LOCAL, S1))
check("in Czech: the choice of single messages", "Jednotlivé zprávy" in html and "Odesílatel nebo předmět obsahuje" in html, url)

# a damaged list never makes a backup fail: a job with the configuration goes on without it,
# the next job with mail sets it aside and makes it again
sh(["sh", "-c", 'printf "this is not sqlite" | dd of="$1" bs=1 seek=0 conv=notrunc 2>/dev/null', "sh", INDEX], user="mail")
CONFONLY = new_job("Configuration only", [LOCAL], {"config": "1", "mail": "none"})
CONFONLY = CONFONLY[0] if CONFONLY else "000000000000"
run_job(CONFONLY)
s = state()["jobs"].get(CONFONLY, {})
log = open("/data/var/backup/engine/logs/" + s["log"]).read() if s.get("log") else ""
check("a damaged list: the backup of the configuration is still ok, without it", s.get("status") == "ok"
      and "list of the messages: not in this backup" in log, (s.get("status"), re.findall(r"list of the messages.*", log)[:2]))
run_job(ALICEJOB)
s = state()["jobs"].get(ALICEJOB, {})
log = open("/data/var/backup/engine/logs/" + s["log"]).read() if s.get("log") else ""
check("then the next job with mail sets the damaged list aside and makes it again", s.get("status") == "ok"
      and "set aside as index.db.damaged and made again" in log and os.path.exists(INDEX + ".damaged")
      and idx("SELECT count(*) > 0 FROM msg WHERE mailbox = '%s';" % A) == "1", (s.get("status"), re.findall(r"list of the messages.*", log)[:2]))
post_form(BASE + "/jobs", suffix="/%s/delete" % CONFONLY)


# moved and deleted: the moved one stays as it was, the deleted one is marked, still listed for the old snapshots
sh(["doveadm", "move", "-u", A, "Sent", "mailbox", "INBOX", "header", "Message-ID", "t2c-encoded@example.net"])
sh(["doveadm", "expunge", "-u", A, "mailbox", "INBOX", "header", "Message-ID", "t2c-deleted@example.net"])
run_job(ALICEJOB)
rows = idx("SELECT subject, gone IS NULL FROM msg WHERE mailbox = '%s' AND subject IN ('to be deleted', 'Fattura n° 12 – ottobre') ORDER BY subject;" % A)
check("a message moved to another folder: the same entry; a deleted one: marked as gone, kept", rows == "Fattura n° 12 – ottobre|1\nto be deleted|0", rows)

# cleaning: what no kept backup can hold any more goes; what a backup may hold stays
idx("UPDATE msg SET gone = 1 WHERE mailbox = '%s' AND subject = 'to be deleted';" % A)
idx("INSERT INTO msg VALUES ('%s', 'recently-gone', 0, 0, 'recently gone', 'x', strftime('%%s','now'));" % A)
run_job(ALICEJOB)
left = idx("SELECT subject FROM msg WHERE mailbox = '%s' AND subject IN ('to be deleted', 'recently gone');" % A)
om = state().get("oldest_mail", {})
check("cleaning: a message gone before the oldest backup kept is removed, one gone since then stays",
      left == "recently gone" and isinstance((om.get(LOCAL) or {}).get("t"), int), (left, om.get(LOCAL)))


# a symbolic link inside a folder chosen (bob's inbox, to alice's message): removed
# before the import, never followed (otherwise alice's message would land in carol)
bob_maildir = "%s/%s/Maildir" % (dom, E["BOB"].split("@")[0])
evil = bob_maildir + "/cur/1700000099.M9P9.evil,S=10:2,S"
os.symlink(alice_msg, evil)
run_job(BOB)
os.remove(evil)
bob_snaps = sorted(snapshots(LOCAL, BOB) or [], key=lambda x: x["time"])
n0 = task_ids()
restore_post(E["BOB"], {"target": E["CAROL"], "name": "From bob"}, dest=LOCAL, snap=bob_snaps[-1]["id"] if bob_snaps else "0" * 64)
done = run_tasks(n0, 1)
r = done[0] if done else {}
leak = [l for l in sh(["grep", "-rl", "restore-me@example.net", carol_home + "/Maildir"]).stdout.split("\n") if "/.From bob." in l]
log = open("/data/var/backup/engine/logs/" + r["log"]).read() if r.get("log") else ""
check("a symbolic link in a restored folder is removed, never followed (alice's message does not reach carol)",
      r.get("status") == "ok" and not leak and "1 symbolic link(s) in the restored Maildir removed" in log, (r.get("note"), r.get("error"), leak))

# the whole server: the commands of the documentation for this destination and snapshot, no password
url, html = ADMIN.get(BASE + "/snapshots/%s/%s/server" % (SMB, SNAP))
d = config()["destinations"][SMB]
check("restore the server: the documented commands for this destination and snapshot, values quoted, without the password",
      ADMIN.status == 200 and "restic restore %s --target /" % SNAP in html and "RESTIC_REPOSITORY=&#039;rclone:BK:%s/%s&#039;" % (d["share"], d["folder"]) in html
      and "RCLONE_CONFIG_BK_HOST=&#039;%s&#039;" % d["host"] in html and d["password"] not in html, ADMIN.status)
kd = config()["destinations"][SFTPKEY]
key_snaps = sorted(snapshots(SFTPKEY, WHOLE) or [], key=lambda x: x["time"])
url, html = ADMIN.get(BASE + "/snapshots/%s/%s/server" % (SFTPKEY, key_snaps[-1]["id"] if key_snaps else SNAP))
check("restore the server from SFTP with a key: a key of one's own instead of a password, the confirmed server keys in known_hosts",
      "RCLONE_CONFIG_BK_KEY_FILE=$PWD/id_backup" in html and "Password:" not in html and kd["hostkeys"]
      and all(k.split(" ")[1] in html for k in kd["hostkeys"]), len(kd["hostkeys"]))
local_list = json.loads(restic(LOCAL, "snapshots", "--json").stdout or "[]")
with_config = sum(1 for x in local_list if "/data/var/backup/engine/staging" in x["paths"])
url, html = ADMIN.get(BASE + "/snapshots?dest=" + LOCAL)
conf_only = [x for x in local_list if "/data/var/backup/engine/staging" in x["paths"] and "/data/domains" not in x["paths"]
             and not any(re.match(r"^/data/domains/[^/]+/[^/]+$", p) and p.rsplit("/", 1)[1] not in ("private", "public", "selector") for p in x["paths"])]
rows_conf = [r for r in re.findall(r"<tr>(.*?)</tr>", html, re.S) if any(x["short_id"] in r for x in conf_only)]
check("a snapshot of the configuration only (with the DKIM keys): 'configuration', no mailbox count, no 'Restore a mailbox'",
      len(conf_only) >= 1 and len(rows_conf) == len(conf_only) and all("configuration" in r and "mailbox" not in r.lower() for r in rows_conf),
      [re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", r))[:120] for r in rows_conf][:2])
check("restore the server is offered only for snapshots with the configuration",
      html.count("/server\"") == with_config and with_config < len(local_list), (html.count("/server\""), with_config, len(local_list)))

# the backups of a job that no longer exists: listed, removed on request, the others stay
TEMP = new_job("Temporary", [LOCAL], {"config": "1", "mail": "none"})
TEMP = TEMP[0] if TEMP else "000000000000"
temp_run = run_job(TEMP)
whole_local = len(snapshots(LOCAL, WHOLE) or [])
temp_local = len(snapshots(LOCAL, TEMP) or [])
post_form(BASE + "/jobs", suffix="/%s/delete" % TEMP)
url, html = ADMIN.get(BASE + "/snapshots?dest=" + LOCAL)
check("a deleted job: its backups are listed as those of a deleted job", TEMP not in config()["jobs"] and temp_local >= 1
      and "deleted job (%s)" % TEMP in html, (temp_local, TEMP in config()["jobs"], (temp_run.stdout + temp_run.stderr)[-300:]))
forget_path = BASE + "/snapshots/%s/forget" % LOCAL
before_q = queued_tasks()
ADMIN.post(forget_path, {"_token": "x", "job": TEMP, "confirm": "yes"})
f = [x for x in Panel.parse(ADMIN.get(BASE + "/snapshots?dest=" + LOCAL)[1]).forms if x["action"].endswith("/forget")
     and ("job", TEMP, "hidden") in x["fields"]]
token = [v for n, v, t in f[0]["fields"] if n == "_token"][0] if f else ""
url, html = ADMIN.post(forget_path, {"_token": token, "job": TEMP})
check("removal without the confirmation ticked, or without the CSRF token: refused, nothing asked of the engine",
      "Tick the confirmation" in html and queued_tasks() == before_q, queued_tasks())
n0 = task_ids()
url, html = ADMIN.post(forget_path, {"_token": token, "job": TEMP, "confirm": "yes"})
done = run_tasks(n0, 1)
r = done[0] if done else {}
check("the backups of the deleted job removed from the destination", r.get("status") == "ok"
      and r.get("note") == "%d backup(s) of the job removed" % temp_local and snapshots(LOCAL, TEMP) == [], (r.get("note"), r.get("error")))
check("the backups of the other jobs on the same destination are untouched", len(snapshots(LOCAL, WHOLE) or []) == whole_local, whole_local)

CS = Panel(E["ALICE"], E["PA"], lang="cs")
url, html = CS.get(BASE + "/snapshots?dest=" + SMB)
check("in Czech: the snapshots page", "Snímky a obnova" in html and "Obnovit schránku" in html, url)

# ===================================================================== verification, statistics, space warnings
print("--- backup: verification, statistics, space warnings")
import html as htmlmod
VT, html = new_destination({"name": "Verify test", "type": "smb", "host": B["DEST_HOST"], "share": "backup", "folder": "verifytest",
                            "user": "bkuser", "password": B["DEST_PASSWORD"], "domain": ""})
VT = VT or "000000000000"
check("a new destination: verified every Sunday at 04:00, a quarter of the data each time", config()["destinations"].get(VT, {}).get("verify")
      == {"every": "week", "weekday": 7, "monthday": 1, "hour": 4, "minute": 0, "parts": 4}, config()["destinations"].get(VT, {}).get("verify"))
VTJOB = new_job("Verify test", [VT], {"config": "1", "mail": "none"})
VTJOB = VTJOB[0] if VTJOB else "000000000000"
run_job(VTJOB)
last = [l for l in (json.loads(x) for x in open("/data/var/backup/engine/history.jsonl")) if l.get("job") == VTJOB][-1:]
l0 = last[0] if last else {}
check("a history line of a backup has the space used on the destination and on /data (for the charts)",
      all(isinstance(l0.get(k), int) and l0.get(k) > 0 for k in ("dest_used", "dest_free", "dest_total", "disk_used", "disk_total")),
      {k: l0.get(k) for k in ("dest_used", "dest_free", "dest_total", "disk_used", "disk_total")})


def verify_now(did):
    f, html = form(BASE + "/destinations/%s/edit" % did, "/verify")
    n0 = task_ids()
    if f:
        ADMIN.post(f["action"], {n: v or "" for n, v, t in f["fields"] if n})
    done = run_tasks(n0, 1)
    return done[0] if done else {}


r1, r2 = verify_now(VT), verify_now(VT)
check("Verify now: the structure and a quarter of the data, then the next quarter", (r1.get("note"), r2.get("note")) ==
      ("the structure and part 1 of 4 of the data: no errors", "the structure and part 2 of 4 of the data: no errors")
      and state()["verify"][VT]["next_part"] == 3, (r1.get("note"), r1.get("error"), r2.get("note")))
url, html = post_form(BASE + "/destinations/%s/edit" % VT, {"verify_every": "day", "verify_hour": "0", "verify_minute": "0", "verify_parts": "1",
                                                           "password": ""})
check("the verification settings saved from the form", config()["destinations"][VT]["verify"] == {"every": "day", "weekday": 7, "monthday": 1,
      "hour": 0, "minute": 0, "parts": 1} and config()["destinations"][VT].get("password") == B["DEST_PASSWORD"], config()["destinations"][VT]["verify"])
r3 = verify_now(VT)
check("all the data read when the setting says so", r3.get("note") == "the structure and all the data: no errors", (r3.get("note"), r3.get("error")))
# a damaged file on the destination: one byte changed in a data file, through rclone like an outside hand
ob = sh(["rclone", "obscure", "-"], input=B["DEST_PASSWORD"] + "\n").stdout.strip()
venv = dict(os.environ, RCLONE_CONFIG="/usr/local/lib/poste-backup/rclone.conf", RCLONE_CONFIG_SV_TYPE="smb", RCLONE_CONFIG_SV_HOST=B["DEST_HOST"],
            RCLONE_CONFIG_SV_USER="bkuser", RCLONE_CONFIG_SV_PASS=ob)
packs = sorted(x for x in subprocess.run(["rclone", "lsf", "-R", "--files-only", "SV:backup/verifytest/data"], env=venv, capture_output=True,
                                          text=True).stdout.split("\n") if x)
pack = "SV:backup/verifytest/data/" + packs[0] if packs else ""
data = subprocess.run(["rclone", "cat", pack], env=venv, capture_output=True).stdout if pack else b""
if len(data) > 200:
    data = data[:100] + bytes([data[100] ^ 0xFF]) + data[101:]
    subprocess.run(["rclone", "rcat", pack], env=venv, input=data, capture_output=True)
r4 = verify_now(VT)
check("a data file changed on the destination: the verification finds it (and says what)", r4.get("status") == "error"
      and (r4.get("error") or "").startswith("the verification found errors: ") and len(r4.get("error")) > 40, (r4.get("status"), r4.get("error")))
url, html = ADMIN.get(BASE + "/")
check("dashboard: the failed verification among the problems and in its card", "Verification of Verify test: the verification found errors" in html
      and 'id="bk-verify-card"' in html, re.findall(r"Verification of Verify test[^<]*", html)[:1])
# a destination that cannot be reached: the failed verification is recorded and shown (not an older result)
post_form(BASE + "/destinations/%s/edit" % VT, {"password": "not-the-password"})
r5 = verify_now(VT)
v5 = state()["verify"].get(VT, {})
url, html = ADMIN.get(BASE + "/")
check("a verification that cannot reach the destination: failed, shown on the dashboard, tried again in an hour",
      (r5.get("error") or "").startswith("verification failed: ") and v5.get("status") == "error" and isinstance(v5.get("retry_at"), int)
      and "Verification of Verify test: verification failed:" in html, (r5.get("error"), v5.get("retry_at")))
url, html = Panel(E["ALICE"], E["PA"], lang="cs").get(BASE + "/")
check("in Czech, the message inside the message is translated too", "Ověření cíle Verify test: ověření selhalo:" in html
      and "not reachable" not in re.findall(r"Ověření cíle Verify test:[^<]*", html)[0] if re.findall(r"Ověření cíle Verify test:[^<]*", html) else False,
      re.findall(r"Ověření cíle Verify test:[^<]*", html)[:1])
post_form(BASE + "/destinations/%s/edit" % VT, {"password": B["DEST_PASSWORD"]})
url, html = ADMIN.get(BASE + "/logs")
check("logs page: the verifications are listed", "Verification</td>" in html or "</i> Verification" in html, url)
url, html = ADMIN.get(BASE + "/destinations/%s/edit" % VT)
check("destination page: the last verifications with what was read and the result", "part 2 of 4" in html and "all of it, every time" in html
      and "the verification found errors" in html, url)
# a scheduled verification: due when its slot is newer than the last one (the engine's tick)
engine_idle()
t_sched = int(time.time())
with open(STATE) as fh:
    stt = json.load(fh)
stt["verify"][VT]["last_slot"] = 1
sh(["sh", "-c", 'cat > "$1.tmp" && mv "$1.tmp" "$1"', "sh", STATE], user="mail", input=json.dumps(stt))
sh(["poste-backup", "tick"])
hist = [json.loads(x) for x in open("/data/var/backup/engine/history.jsonl")]
sched_runs = [l for l in hist if l.get("kind") == "verify" and l.get("dest") == VT and l.get("reason") == "schedule" and l.get("start", 0) >= t_sched]
check("a verification due by its schedule is run by the engine's tick", len(sched_runs) == 1 and state()["verify"][VT]["last_slot"] > 1, len(sched_runs))
# statistics on the dashboard
url, html = ADMIN.get(BASE + "/")
m = re.search(r'data-stats="([^"]*)"', html)
stats = json.loads(htmlmod.unescape(m.group(1))) if m else {}
used = {x["name"]: x["data"] for x in stats.get("used", [])}
check("statistics: 30 days, the space used on the destination today, the data added and the duration of the job",
      len(stats.get("labels", [])) == 30 and isinstance((used.get("Verify test") or [None])[-1], int)
      and any(x["name"] == "Verify test" and x["data"][-1] > 0 for x in stats.get("added", []))
      and isinstance(stats.get("disk", [None])[-1], int) and html.count('<canvas id="bk-chart-') == 4, (len(stats.get("labels", [])), used.get("Verify test", [])[-1:]))
lens = []
for d in ("90", "365", "8", "7", "1"):
    url, html = ADMIN.get(BASE + "/?days=" + d)
    m = re.search(r'data-stats="([^"]*)"', html)
    lens.append(len(json.loads(htmlmod.unescape(m.group(1)))["labels"]) if m else 0)
check("statistics: 90 days, a year, 7 days, one day per hour; another value is 30 days", lens == [90, 365, 30, 7, 24], lens)
# the space warnings
before = json.dumps(config()["settings"])
ADMIN.post(BASE + "/settings/space", {"_token": "x", "space_warn": "1", "space_full": "2"})
f, html = form(BASE + "/destinations", "/settings/space")
url, html = post_form(BASE + "/destinations", {"space_warn": "90", "space_full": "80"}, suffix="/settings/space")
check("space warnings: without the CSRF token, or yellow not below red, nothing saved (with the reason)", json.dumps(config()["settings"]) == before
      and "yellow must start below red" in html, re.findall(r'alert-danger">([^<]*)', html)[:1])
post_form(BASE + "/destinations", {"space_warn": "1", "space_full": "2"}, suffix="/settings/space")
url, html = ADMIN.get(BASE + "/")
disk_bar = re.search(r'<div class="bk-bar"><span class="([^"]*)" style="width: (\d+)%', html)
check("space warnings saved: the bars take them (here everything is above 2%: red)", config()["settings"]["space_warn"] == 1
      and config()["settings"]["space_full"] == 2 and disk_bar is not None and disk_bar.group(1) == "bk-full", disk_bar.groups() if disk_bar else None)
post_form(BASE + "/destinations", {"space_warn": "80", "space_full": "90"}, suffix="/settings/space")
url, html = ADMIN.get(BASE + "/destinations")
check("destinations page: the last verification of each destination, the space warnings", "Last verification" in html
      and 'name="space_warn" value="80"' in html and "the verification found errors" in html, url)
CSV = Panel(E["ALICE"], E["PA"], lang="cs")
url, html = CSV.get(BASE + "/")
url2, html2 = CSV.get(BASE + "/destinations/%s/edit" % VT)
check("in Czech: statistics and verification", "Statistiky" in html and "Poslední ověření" in html and "Ověřit nyní" in html2, url)

# ===================================================================== REST server, append-only
print("--- backup: a REST server in append-only mode")
RH = B["REST_HOST"]
rest_values = {"name": "REST append-only", "type": "rest", "host": RH, "port": "8000", "folder": "mailserver", "user": "bk",
               "password": B["DEST_PASSWORD"], "append_only": "1"}


def fingerprint(path):
    out = sh(["openssl", "x509", "-in", path, "-noout", "-fingerprint", "-sha256"]).stdout.strip()
    return "SHA256 " + out.split("=", 1)[1] if "=" in out else "?"


def check_json(values):
    f, html = form(BASE + "/destinations/new")
    data = {n: (v or "") for n, v, t in f["fields"] if n and not t.endswith(":off") and t not in ("submit", "button")}
    data.update(values)
    url, body = ADMIN.post(BASE + "/destinations/check", data)
    try:
        return json.loads(body)
    except ValueError:
        return {"status": "?", "body": body[:200]}


r0 = check_json(rest_values)
check("REST, the form's Test: first the server's certificate, its fingerprint and names (nothing sent to the server before)",
      r0.get("status") == "cert" and r0.get("fingerprint") == fingerprint("/tests/rest-cert.pem") and r0.get("names") == RH
      and "BEGIN CERTIFICATE" in r0.get("pem", ""), {k: r0.get(k) for k in ("status", "fingerprint", "names")})
r1 = check_json(dict(rest_values, cert=r0.get("pem", "")))
check("the certificate confirmed: reachable, no repository yet, no free space reported, append-only said",
      r1.get("status") == "ok" and any("does not exist yet" in l for l in r1.get("lines", [])) and any("REST server" in l for l in r1.get("lines", []))
      and any("append-only" in l for l in r1.get("lines", [])), r1.get("lines"))
r2 = check_json(dict(rest_values, cert=r0.get("pem", ""), password="wrong-password"))
r3 = check_json(dict(rest_values, cert=open("/tests/rest-other.pem").read()))
ip = sh(["getent", "hosts", RH]).stdout.split()[0] if sh(["getent", "hosts", RH]).stdout.split() else "?"
r4 = check_json(dict(rest_values, host=ip))
check("refused: a wrong password, another certificate than the server's, an address that is not in the certificate",
      r2.get("status") == "error" and "refused the user or the password" in " ".join(r2.get("lines", []))
      and r3.get("status") == "error" and "not reachable" in " ".join(r3.get("lines", []))
      and r4.get("status") == "error" and ("the certificate is for %s, not for %s" % (RH, ip)) in " ".join(r4.get("lines", [])),
      (r2.get("lines"), r3.get("lines"), r4.get("lines")))
REST, html = new_destination(dict(rest_values, cert=r0.get("pem", "")))
REST = REST or "000000000000"
d = config()["destinations"].get(REST, {})
check("the REST destination saved with its certificate, append-only", d.get("type") == "rest" and d.get("append_only") is True
      and "BEGIN CERTIFICATE" in d.get("cert", ""), {k: d.get(k) for k in ("type", "append_only")})
RJOB = new_job("To REST", [REST], {"config": "1", "mail": "none", "keep_hourly": "1", "keep_daily": "0"})
RJOB = RJOB[0] if RJOB else "000000000000"
outs = [run_job(RJOB).stdout.strip() for _ in range(3)]
snaps = snapshots(REST, RJOB)
s = state()["jobs"].get(RJOB, {})
log = open("/data/var/backup/engine/logs/" + s["log"]).read() if s.get("log") else ""
check("three runs, keep 1 per hour: the three snapshots stay on the append-only server (nothing removed from here), the log says why",
      snaps is not None and len(snaps) == 3 and s.get("status") == "ok" and "append-only destination: the old snapshots are removed on the server" in log,
      (outs, len(snaps or []), s.get("status")))
check("the password of the REST server is in no log of the run", B["DEST_PASSWORD"] not in log, "not in the log")
url, html = ADMIN.get(BASE + "/snapshots?dest=" + REST)
check("snapshots page: the append-only note, no removal of the backups of a job there", "accepts only new backups" in html
      and 'action="/admin/backup/snapshots/%s/forget"' % REST not in html and html.count('<code>') >= 3, url)
before_q = queued_tasks()
f = [x for x in Panel.parse(ADMIN.get(BASE + "/snapshots?dest=" + SMB)[1]).forms if x["action"].endswith("/forget")]
token = [v for n, v, t in f[0]["fields"] if n == "_token"][0] if f else ""
url, html = ADMIN.post(BASE + "/snapshots/%s/forget" % REST, {"_token": token, "job": RJOB, "confirm": "yes"})
n0 = task_ids()
sh(["sh", "-c", 'cat > "$1"', "sh", QUEUE + "/1000000005-forget-0e0e0e0e0e0e.json"], user="mail", input=json.dumps({"dest": REST, "job": RJOB}))
done = run_tasks(n0, 1)
check("removing the backups of a job there: refused by the admin, and by the engine for a request written behind it",
      "accepts only new backups" in html and queued_tasks() == before_q and [t.get("error") for t in done]
      == ["this destination accepts only new backups: remove them on the server"] and len(snapshots(REST, RJOB) or []) == 3,
      [t.get("error") for t in done])
rv = verify_now(REST)
check("a verification of the append-only server works", rv.get("status") == "ok" and (rv.get("note") or "").endswith("no errors"), (rv.get("note"), rv.get("error")))
url, html = ADMIN.get(BASE + "/snapshots/%s/%s/server" % (REST, sorted(snaps or [{"id": "0" * 64}], key=lambda x: x["time"])[-1]["id"]))
check("restore the server from it: the commands with the confirmed certificate and --cacert, no password", "BEGIN CERTIFICATE" in html
      and "--cacert server.pem" in html and "rest:https://bk:" in html and B["DEST_PASSWORD"] not in html, ADMIN.status)
# a server whose certificate is not the confirmed one: the backup fails, nothing is sent
post_form(BASE + "/destinations/%s/edit" % REST, {"cert": open("/tests/rest-other.pem").read(), "password": ""})
run_job(RJOB)
s = state()["jobs"].get(RJOB, {})
err = (s.get("destinations", {}).get(REST) or {}).get("error") or ""
check("another certificate saved for that server: the backup fails before sending anything, and says why", s.get("status") == "error"
      and err.startswith("not reachable") and "certificate" in err.lower(), err)
post_form(BASE + "/destinations/%s/edit" % REST, {"cert": r0.get("pem", ""), "password": ""})
url, html = Panel(E["ALICE"], E["PA"], lang="cs").get(BASE + "/destinations/new")
check("in Czech: the REST server type", "Server REST (restic rest-server)" in html, url)

# one run at a time
with open("/run/poste-backup.lock") as f:
    fcntl.flock(f, fcntl.LOCK_EX)
    r = sh(["poste-backup", "tick"])
    fcntl.flock(f, fcntl.LOCK_UN)
check("while a run holds the lock, another one does not start", "busy" in r.stdout, r.stdout.strip())

print("FAILURES: %d %s" % (len(FAILS), FAILS))
sys.exit(len(FAILS))
