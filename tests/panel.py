"""Drives the Poste.io admin over HTTPS, as an administrator does with a browser.
Runs inside the test container (https://localhost)."""
import http.cookiejar, ssl, urllib.error, urllib.parse, urllib.request
from html.parser import HTMLParser

BASE = "https://localhost"
CTX = ssl._create_unverified_context()   # self-signed certificate of a fresh container


def env(path="/tests/test.env"):
    """Test accounts written by run.sh (random passwords)."""
    e = {}
    for line in open(path):
        k, v = line.strip().split("=", 1)
        e[k] = v
    return e


class Forms(HTMLParser):
    """Forms of a page (action, method, fields) and its links. Like a browser,
    unchecked checkboxes are marked so that they are not sent."""
    def __init__(self):
        super().__init__()
        self.forms, self.links = [], []
        self.cur = self.sel = self.ta = None

    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == "a" and a.get("href"):
            self.links.append(a["href"])
        if tag == "form":
            self.cur = {"action": a.get("action", ""), "method": a.get("method", "get"), "fields": []}
            self.forms.append(self.cur)
        elif self.cur is not None and tag in ("input", "textarea"):
            t = a.get("type", tag)
            if t in ("checkbox", "radio") and "checked" not in a:
                t += ":off"
            self.cur["fields"].append((a.get("name"), a.get("value", "on" if t.startswith("checkbox") else ""), t))
            if tag == "textarea":
                self.ta = len(self.cur["fields"]) - 1
        elif self.cur is not None and tag == "select":
            self.sel = a.get("name")
            self.cur["fields"].append((self.sel, None, "select"))
        elif self.sel is not None and tag == "option":
            n, v, t = self.cur["fields"][-1]
            if v is None or a.get("selected") is not None:
                self.cur["fields"][-1] = (n, a.get("value"), t)

    def handle_data(self, data):
        if self.ta is not None:
            n, v, t = self.cur["fields"][self.ta]
            self.cur["fields"][self.ta] = (n, v + data, t)

    def handle_endtag(self, tag):
        if tag == "form":
            self.cur = None
        elif tag == "select":
            self.sel = None
        elif tag == "textarea":
            self.ta = None


class Panel:
    def __init__(self, user, password, lang=None):
        self.op = urllib.request.build_opener(urllib.request.HTTPSHandler(context=CTX),
                                              urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        if lang:
            # the admin picks its language from the browser's (en or cs)
            self.op.addheaders = [("Accept-Language", lang)]
        self.login(user, password)

    def _open(self, path, body=None):
        """Returns (final url, page); the HTTP status is kept in self.status
        (an error status such as 403 is an answer to check, not a crash)."""
        try:
            r = self.op.open(BASE + path, body, timeout=120)
            self.status = r.status
            return r.geturl(), r.read().decode()
        except urllib.error.HTTPError as e:
            self.status = e.code
            return e.geturl(), e.read().decode(errors="replace")

    def get(self, path):
        return self._open(path)

    def post(self, path, data):
        return self._open(path, urllib.parse.urlencode(data).encode())

    @staticmethod
    def parse(html):
        p = Forms()
        p.feed(html)
        return p

    def login(self, user, password):
        url, html = self.get("/admin/login")
        f = [x for x in self.parse(html).forms if any(n and "password" in n for n, _, _ in x["fields"])][0]
        data = {n: v for n, v, t in f["fields"] if n and not t.endswith(":off")}
        data[[n for n, _, t in f["fields"] if n and "password" in n][0]] = password
        data[[n for n, _, t in f["fields"] if n and t in ("text", "email")][0]] = user
        self.post(urllib.parse.urlparse(f["action"]).path or "/admin/login", data)

    def submit(self, path, values, drop=()):
        """GET a form, keep what the page has (like a browser), change `values`,
        leave out the names in `drop`, POST it back."""
        url, html = self.get(path)
        f = [x for x in self.parse(html).forms if x["method"].lower() == "post"][0]
        data = {n: (v or "") for n, v, t in f["fields"]
                if n and not t.endswith(":off") and t not in ("submit", "button", "file")}
        data.update(values)
        for n in drop:
            data.pop(n, None)
        action = f["action"] if f["action"].startswith("/") else path
        return self.post(action, data)

    def links(self, path):
        url, html = self.get(path)
        return self.parse(html).links
