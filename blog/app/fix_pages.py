"""Strip dead Jetpack contact-form remnants from the standalone pages.

The forms were flattened into bare labels by the Markdown conversion; the form
itself cannot exist on the new site, so the labels are noise. Prose is untouched.
"""
import re, sqlite3

DB = "/opt/ropo-blog/data/blog.db"
# The tail a flattened grunion form leaves behind.
FORM_MARKERS = [
    r"^\s*Name\s*\(required\)\s*$",
    r"^\s*Email\s*\(required\)\s*$",
    r"^\s*Website\s*$",
    r"^\s*Message\s*$",
    r"^\s*Comment\s*$",
    r"^\s*I confirm that I['’]m not being nasty\s*\(required\)\s*$",
    r"^\s*Submit\s*$",
    r"^\s*\(required\)\s*$",
]
RX = re.compile("|".join(FORM_MARKERS), re.M | re.I)


def strip_form(body):
    lines = (body or "").splitlines()
    keep, removed = [], 0
    for ln in lines:
        if RX.match(ln):
            removed += 1
            continue
        keep.append(ln)
    out = "\n".join(keep)
    out = re.sub(r"\n{3,}", "\n\n", out).strip()
    return out, removed


conn = sqlite3.connect(DB)
for pid, slug, body in conn.execute(
        "SELECT id,slug,body FROM posts WHERE kind='page'").fetchall():
    nb, n = strip_form(body)
    if n:
        conn.execute("UPDATE posts SET body=?, updated_at=datetime('now') WHERE id=?", (nb, pid))
    print(f"  /{slug:12} {len(body or ''):4}b -> {len(nb):4}b   ({n} form lines removed)")
conn.commit()
conn.close()
