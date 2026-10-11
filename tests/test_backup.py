"""End-to-end tests of the backup add-on, run inside the test container by
run.sh, with real destinations next to it (tests/backup-dest: Samba and SFTP;
rclone's S3 server) and a tmpfs at /backup/local as a mounted volume.

The admin is used like a browser (forms, CSRF tokens, redirects); then what
happened is checked where it matters: on the destinations, by listing the
snapshots and by restoring files and comparing them with the originals.
Cron runs the engine every minute in the container too: the tests wait for
the engine's lock instead of assuming who runs what.
Every check prints PASS or FAIL; the exit code is the number of failures."""
import fcntl, json, os, re, shutil, stat, subprocess, sys, time
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

SMBBAD, html = new_destination({"name": "Samba, wrong password", "type": "smb", "host": B["DEST_HOST"], "share": "backup",
                                "folder": "other", "user": "bkuser", "password": "wrong-" + B["DEST_PASSWORD"], "domain": ""})
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

# the restore procedure of docs/backup.md, as written there: the mailbox restored
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
MIXED = new_job("Mixed", [SMBBAD, NOTMOUNTED, S3], {"config": "1", "mail": "none"})
MIXED = MIXED[0] if MIXED else None
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

# one run at a time
with open("/run/poste-backup.lock") as f:
    fcntl.flock(f, fcntl.LOCK_EX)
    r = sh(["poste-backup", "tick"])
    fcntl.flock(f, fcntl.LOCK_UN)
check("while a run holds the lock, another one does not start", "busy" in r.stdout, r.stdout.strip())

print("FAILURES: %d %s" % (len(FAILS), FAILS))
sys.exit(len(FAILS))
