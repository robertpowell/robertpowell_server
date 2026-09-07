"""Check every link in every post; write a report. Read-only — changes nothing."""
import concurrent.futures as cf
import json, os, re, sqlite3, ssl, sys, urllib.request, urllib.error

DB = "/opt/ropo-blog/data/blog.db"
MEDIA = "/opt/ropo-blog/data/media"
OUT = "/tmp/claude-0/-root/12da5823-96e3-4885-99c8-adb42617ecbd/scratchpad/linkreport.json"
UA = "Mozilla/5.0 (compatible; link-audit/1.0; +https://robertpowell.com)"
CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE

IMG  = re.compile(r'!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)')
LINK = re.compile(r'(?<!!)\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)')
AUTO = re.compile(r'<(https?://[^>\s]+)>')
BARE = re.compile(r'(?<![\(<\]])\bhttps?://[^\s<>\)\]]+')


def collect():
    conn = sqlite3.connect(DB)
    refs = []
    for slug, body in conn.execute("SELECT slug,body FROM posts"):
        b = body or ""
        seen = set()
        for kind, rx, grp in (("image", IMG, 2), ("link", LINK, 2),
                              ("auto", AUTO, 1), ("bare", BARE, 0)):
            for m in rx.finditer(b):
                u = m.group(grp) if grp else m.group(0)
                if (kind, u) not in seen:
                    seen.add((kind, u))
                    refs.append({"slug": slug, "kind": kind, "url": u})
    conn.close()
    return refs


def check(url):
    if url.startswith("/media/"):
        p = os.path.join(MEDIA, url[len("/media/"):])
        return ("ok", 200, "local file") if os.path.exists(p) else ("dead", 0, "file missing")
    if url.startswith("/"):
        return ("local", 0, "site-relative")
    if not url.startswith(("http://", "https://")):
        return ("skip", 0, "not http")
    for method in ("HEAD", "GET"):
        try:
            r = urllib.request.Request(url, method=method, headers={"User-Agent": UA})
            with urllib.request.urlopen(r, timeout=12, context=CTX) as resp:
                final = resp.geturl()
                note = "redirected -> " + final if final.rstrip("/") != url.rstrip("/") else ""
                return ("ok", resp.status, note)
        except urllib.error.HTTPError as e:
            if e.code in (403, 405, 429) and method == "HEAD":
                continue
            return ("dead" if e.code in (404, 410) else "error", e.code, str(e.reason)[:60])
        except Exception as e:
            if method == "HEAD":
                continue
            return ("dead", 0, type(e).__name__ + ": " + str(e)[:60])
    return ("dead", 0, "no response")


def main():
    refs = collect()
    urls = sorted({r["url"] for r in refs})
    print(f"  checking {len(urls)} distinct URLs across {len(refs)} references...", flush=True)
    results = {}
    with cf.ThreadPoolExecutor(max_workers=8) as ex:
        for u, res in zip(urls, ex.map(check, urls)):
            results[u] = res
    for r in refs:
        r["status"], r["code"], r["note"] = results[r["url"]]
    json.dump(refs, open(OUT, "w"), indent=1)

    from collections import Counter
    c = Counter(results[u][0] for u in urls)
    print("  by outcome (distinct URLs):", dict(c))
    print()
    print("  DEAD / ERROR:")
    for u in urls:
        st, code, note = results[u]
        if st in ("dead", "error"):
            print(f"    [{st:5} {code:3}] {u[:78]}")
            if note:
                print(f"              {note[:74]}")


if __name__ == "__main__":
    main()
