"""Three fixes: page/post separation, date-based slugs, and tidier titles."""
import re, sqlite3, datetime

DB = "/opt/ropo-blog/data/blog.db"
PAGE_SLUGS = {"cookies", "copyright", "contactme"}

conn = sqlite3.connect(DB)
cols = [r[1] for r in conn.execute("PRAGMA table_info(posts)")]
if "kind" not in cols:
    conn.execute("ALTER TABLE posts ADD COLUMN kind TEXT NOT NULL DEFAULT 'post'")
    print("  added column: posts.kind")

# 1. mark the pages
n = 0
for slug in PAGE_SLUGS:
    n += conn.execute("UPDATE posts SET kind='page' WHERE slug=?", (slug,)).rowcount
print(f"  marked as pages : {n}")

# 2. numeric slugs -> date-based, keeping them unique
taken = {s for (s,) in conn.execute("SELECT slug FROM posts")}
fixed = 0
for pid, slug, title, pub in conn.execute(
        "SELECT id,slug,title,published_at FROM posts WHERE slug GLOB '[0-9]*'").fetchall():
    date = (pub or "")[:10] or "undated"
    base = date
    if (title or "").strip():
        t = re.sub(r"[^\w\s-]", "", title.lower())
        t = re.sub(r"[-\s]+", "-", t).strip("-")[:40]
        if t:
            base = f"{date}-{t}"
    new, i = base, 2
    while new in taken:
        new, i = f"{base}-{i}", i + 1
    taken.discard(slug); taken.add(new)
    conn.execute("UPDATE posts SET slug=? WHERE id=?", (new, pid))
    fixed += 1
print(f"  slugs rewritten : {fixed}")

# 3. give untitled posts a readable heading from their date
untitled = 0
for pid, pub in conn.execute(
        "SELECT id,published_at FROM posts WHERE trim(title)='' ").fetchall():
    if pub:
        d = datetime.datetime.strptime(pub[:10], "%Y-%m-%d")
        conn.execute("UPDATE posts SET title=? WHERE id=?",
                     (d.strftime("%-d %B %Y"), pid))
        untitled += 1
print(f"  untitled headed : {untitled}")

conn.commit()
print()
for k, c in conn.execute("SELECT kind,COUNT(*) FROM posts GROUP BY kind"):
    print(f"  {k:6}: {c}")
print("  numeric slugs left:",
      conn.execute("SELECT COUNT(*) FROM posts WHERE slug GLOB '[0-9]*'").fetchone()[0])
conn.close()
