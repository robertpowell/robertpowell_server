"""Re-extract the Ghost archive properly: keep images, links and formatting.

The first pass stripped every HTML tag, which lost 91 images across 89 posts.
This converts HTML -> Markdown with html2text, and rewrites Ghost's
/content/images/wordpress/YYYY/MM/f.jpg to /media/YYYY/MM/f.jpg so the files
can be served locally instead of from the dying cPanel box.
"""
import json, os, re, shutil, sqlite3, sys
import html2text

GHOST = ("/mnt/ropo_volume/robertpowell-migration/unpacked/"
         "backup-8.26.2026_17-49-19_robertpo/homedir/public_html/"
         "wp-content/uploads/ghost-exports/wp_ghost_export.json")
UPLOADS = ("/mnt/ropo_volume/robertpowell-migration/unpacked/"
           "backup-8.26.2026_17-49-19_robertpo/homedir/public_html/"
           "wp-content/uploads")
MEDIA = "/opt/ropo-blog/data/media"
DB = "/opt/ropo-blog/data/blog.db"

conv = html2text.HTML2Text()
conv.body_width = 0          # never hard-wrap
conv.unicode_snob = True     # keep smart quotes
conv.protect_links = True
conv.images_as_html = False

GHOST_IMG = re.compile(r"/content/images/wordpress/(\d{4})/(\d{2})/([^\"')\s]+)")

def localise(html):
    """Point Ghost image paths at /media/ and note which files we need."""
    wanted = set()
    def sub(m):
        yr, mo, fn = m.groups()
        wanted.add((yr, mo, fn))
        return f"/media/{yr}/{mo}/{fn}"
    return GHOST_IMG.sub(sub, html), wanted

def main():
    data = json.load(open(GHOST, encoding="utf-8", errors="replace"))
    conn = sqlite3.connect(DB)
    need, updated, with_img = set(), 0, 0

    for p in data["data"]["posts"]:
        html = p.get("html") or ""
        if not html.strip():
            continue
        html, wanted = localise(html)
        need |= wanted
        md = conv.handle(html).strip()
        if "![" in md or "/media/" in md:
            with_img += 1

        slug = re.sub(r"[^a-z0-9-]", "", (p.get("slug") or "").lower())[:80]
        cur = conn.execute(
            "UPDATE posts SET body=?, updated_at=datetime('now') WHERE slug=?",
            (md, slug))
        if cur.rowcount == 0:                       # truncated-slug cases
            gs = re.sub(r"[^a-z0-9-]", "", (p.get("slug") or "").lower())
            for (s,) in conn.execute("SELECT slug FROM posts").fetchall():
                if len(s) >= 60 and gs.startswith(s[:60]):
                    cur = conn.execute(
                        "UPDATE posts SET body=?, updated_at=datetime('now') WHERE slug=?",
                        (md, s))
                    break
        updated += cur.rowcount
    conn.commit()

    copied = missing = 0
    for yr, mo, fn in sorted(need):
        src = os.path.join(UPLOADS, yr, mo, fn)
        if not os.path.exists(src):
            missing += 1
            continue
        dst = os.path.join(MEDIA, yr, mo, fn)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        if not os.path.exists(dst):
            shutil.copy2(src, dst)
        copied += 1

    print(f"  post bodies rewritten     : {updated}")
    print(f"  posts now carrying images : {with_img}")
    print(f"  distinct images referenced: {len(need)}")
    print(f"  copied into /media        : {copied}")
    print(f"  referenced but MISSING    : {missing}")
    conn.close()

if __name__ == "__main__":
    main()
