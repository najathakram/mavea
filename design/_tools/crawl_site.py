"""Crawl the local store and report reachability + basic page health.

Code before models: reachability is a graph problem, not a judgment call.
Starts at /, follows same-origin links breadth-first, and for every URL
records: status, how it was reached (first referrer), outbound internal
links, h1 count, whether the porcelain chrome rendered, and whether the
forbidden name leaked.

    python crawl_site.py http://localhost:8088 out.json
"""

import json
import re
import sys
import urllib.request
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit


class LinkParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []
        self.h1 = 0
        self.title = ""
        self._in_title = False

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "a" and a.get("href"):
            self.links.append(a["href"])
        if tag == "h1":
            self.h1 += 1
        if tag == "title":
            self._in_title = True

    def handle_endtag(self, tag):
        if tag == "title":
            self._in_title = False

    def handle_data(self, data):
        if self._in_title:
            self.title += data


def norm(base, href):
    """Absolute URL -> canonical same-origin path, or None."""
    u = urljoin(base, href)
    s = urlsplit(u)
    b = urlsplit(base)
    if s.netloc != b.netloc or s.scheme not in ("http", "https"):
        return None
    path = s.path or "/"
    # Skip admin, feeds, assets, actions
    if re.search(r"wp-(admin|login|json)|/feed|\.(jpg|jpeg|png|webp|css|js|svg|ico|xml)$", path):
        return None
    if re.search(r"add-to-cart|remove_item|\?wc-ajax|logout", u):
        return None
    # Keep meaningful queries (search, filters) but drop tracking
    q = s.query if re.search(r"(^|&)(s|orderby|min_price|max_price|product_cat)=", s.query) else ""
    return path + ("?" + q if q else "")


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": "slk-crawler"})
    with urllib.request.urlopen(req, timeout=25) as r:
        return r.status, r.read().decode("utf-8", "replace"), r.geturl()


def main():
    base, out_path = sys.argv[1], sys.argv[2]
    seen, queue, pages = {}, ["/"], {}
    referrer = {"/": "(start)"}

    while queue and len(seen) < 150:
        path = queue.pop(0)
        if path in seen:
            continue
        seen[path] = True
        url = urljoin(base, path)
        try:
            status, html, final = fetch(url)
        except Exception as e:                                     # noqa: BLE001
            pages[path] = {"status": f"ERROR {e}", "from": referrer.get(path)}
            continue

        p = LinkParser()
        try:
            p.feed(html)
        except Exception:                                          # noqa: BLE001
            pass

        internal = []
        for href in p.links:
            n = norm(base, href)
            if n and n not in internal:
                internal.append(n)
        for n in internal:
            if n not in seen and n not in queue:
                queue.append(n)
                referrer.setdefault(n, path)

        redirected = urlsplit(final).path != urlsplit(url).path
        pages[path] = {
            "status": status,
            "from": referrer.get(path),
            "title": p.title.strip()[:70],
            "h1": p.h1,
            "links_out": len(internal),
            "chrome": (".slk-header" in html or "slk-header" in html),
            "forbidden_name": ("AESHAL" in html or "Aeshal" in html),
            "redirect": redirected,
            "bytes": len(html),
        }

    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(pages, f, indent=1)
    print(f"crawled {len(pages)} URLs -> {out_path}")


if __name__ == "__main__":
    main()
