"""Import the recovered 2003-2017 archive (Markdown + front matter) into the blog."""
import os, re, sys, sqlite3
from datetime import datetime

SRC = sys.argv[1] if len(sys.argv) > 1 else \
    "/mnt/ropo_volume/robertpowell-migration/archive/posts"
DB = os.environ.get("BLOG_DB", "/opt/ropo-blog/data/blog.db")
STATUS = os.environ.get("IMPORT_STATUS", "draft")   # draft by default - nothing goes live

def parse(path):
    raw = open(path, encoding="utf-8", errors="replace").read()
    meta, body = {}, raw
    m = re.match(r"^---\n(.*?)\n---\n?(.*)$", raw, re.S)
    if m:
        for line in m.group(1).splitlines():
            if ":" in line:
                k, v = line.split(":", 1)
                meta[k.strip()] = v.strip()
        body = m.group(2)
    return meta, body.strip()

conn = sqlite3.connect(DB)
conn.execute("PRAGMA foreign_keys=ON")
added = skipped = 0
files = sorted(f for f in os.listdir(SRC) if f.endswith(".md"))

for fn in files:
    meta, body = parse(os.path.join(SRC, fn))
    slug = meta.get("slug") or os.path.splitext(fn)[0]
    slug = re.sub(r"[^a-z0-9-]", "", slug.lower())[:80] or "untitled"
    date = meta.get("date", "")
    title = meta.get("title", "").strip()
    if title in ("(no title)", ""):
        title = ""
    pub = f"{date} 12:00:00" if re.match(r"^\d{4}-\d{2}-\d{2}$", date) else None

    if conn.execute("SELECT 1 FROM posts WHERE slug=?", (slug,)).fetchone():
        skipped += 1
        continue
    cur = conn.execute(
        "INSERT INTO posts (slug,title,body,status,published_at,source,legacy_url) "
        "VALUES (?,?,?,?,?,?,?)",
        (slug, title, body, STATUS, pub, "archive-2003-2017",
         f"https://robertpowell.com/{meta.get('slug','')}/"))
    pid = cur.lastrowid
    for t in (meta.get("tags") or "").split(","):
        t = t.strip()
        if not t:
            continue
        conn.execute("INSERT OR IGNORE INTO tags (name) VALUES (?)", (t,))
        tid = conn.execute("SELECT id FROM tags WHERE name=?", (t,)).fetchone()[0]
        conn.execute("INSERT OR IGNORE INTO post_tags (post_id,tag_id) VALUES (?,?)", (pid, tid))
    added += 1

conn.commit()
n = conn.execute("SELECT COUNT(*) FROM posts").fetchone()[0]
empty = conn.execute("SELECT COUNT(*) FROM posts WHERE length(trim(body))=0").fetchone()[0]
print(f"  files seen : {len(files)}")
print(f"  imported   : {added}  (status={STATUS})")
print(f"  skipped    : {skipped}")
print(f"  in database: {n}   of which empty-bodied: {empty}")
conn.close()
