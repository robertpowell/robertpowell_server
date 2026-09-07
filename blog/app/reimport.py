"""Definitive re-import of the Ghost archive.

Fixes found on 2026-09-03:
  * malformed HTML leaked into image alt text  -> parse/repair with BeautifulSoup first
  * hidden geo divs bled into the body (52)    -> stripped, coordinates kept as metadata
  * html2text protect_links wrapped every URL  -> disabled, so no stray <...> in the text
  * HTML entities survived as literals         -> unescaped after conversion
"""
import html as htmllib
import json, os, re, shutil, sqlite3, sys
import html2text
from bs4 import BeautifulSoup

BACKUP = ("/mnt/ropo_volume/robertpowell-migration/unpacked/"
          "backup-8.26.2026_17-49-19_robertpo/homedir/public_html")
GHOST = os.path.join(BACKUP, "wp-content/uploads/ghost-exports/wp_ghost_export.json")
UPLOADS = os.path.join(BACKUP, "wp-content/uploads")
MEDIA = "/opt/ropo-blog/data/media"
DB = "/opt/ropo-blog/data/blog.db"

GHOST_IMG = re.compile(r"/content/images/wordpress/(\d{4})/(\d{2})/([^\"')\s]+)")


def make_converter():
    c = html2text.HTML2Text()
    c.body_width = 0
    c.unicode_snob = True
    c.protect_links = False      # no <http://...> wrappers
    c.images_as_html = False
    c.single_line_break = False
    c.wrap_links = False
    return c


def clean_html(raw):
    """Repair the HTML, pull out geo, and tidy image alts. Returns (html, lat, lon)."""
    soup = BeautifulSoup(raw, "html.parser")
    lat = lon = None

    for div in soup.select("div.geo, div.geo-post"):
        la, lo = div.select_one(".latitude"), div.select_one(".longitude")
        if la and lo:
            lat, lon = la.get_text(strip=True), lo.get_text(strip=True)
        div.decompose()
    # any stray hidden div that only holds coordinates
    for div in soup.find_all("div", style=re.compile(r"display:\s*none")):
        div.decompose()

    for img in soup.find_all("img"):
        alt = img.get("alt") or ""
        alt = re.sub(r"<[^>]*>", "", alt).strip()
        alt = re.sub(r"^\W+|\W+$", "", alt)
        if alt and not re.fullmatch(r"[\s\W]*", alt):
            img["alt"] = alt
        else:
            del img["alt"]
        for junk in ("style", "class", "width", "height", "border", "id"):
            if junk in img.attrs:
                del img[junk]

    return str(soup), lat, lon


def localise(html):
    wanted = set()
    def sub(m):
        yr, mo, fn = m.groups()
        wanted.add((yr, mo, fn))
        return f"/media/{yr}/{mo}/{fn}"
    return GHOST_IMG.sub(sub, html), wanted


def tidy_markdown(md):
    md = htmllib.unescape(md)                        # &#8217; -> ’
    md = re.sub(r"<(/?)(?:a|p|div|span|br|em|strong|img|blockquote)\b[^>]*>", "", md, flags=re.I)
    md = re.sub(r"[ \t]+\n", "\n", md)
    md = re.sub(r"\n{3,}", "\n\n", md)
    md = re.sub(r"^[ \t]*\|\s*$", "", md, flags=re.M)
    return md.strip()


def main():
    data = json.load(open(GHOST, encoding="utf-8", errors="replace"))
    conn = sqlite3.connect(DB)
    cols = [r[1] for r in conn.execute("PRAGMA table_info(posts)")]
    for col in ("lat", "lon"):
        if col not in cols:
            conn.execute(f"ALTER TABLE posts ADD COLUMN {col} TEXT")

    conv = make_converter()
    need = set()
    updated = geo_found = 0

    slugs = {s for (s,) in conn.execute("SELECT slug FROM posts")}

    for p in data["data"]["posts"]:
        raw = p.get("html") or ""
        html, lat, lon = clean_html(raw)
        html, wanted = localise(html)
        need |= wanted
        md = tidy_markdown(conv.handle(html))
        if lat:
            geo_found += 1

        gs = re.sub(r"[^a-z0-9-]", "", (p.get("slug") or "").lower())
        slug = gs[:80]
        if slug not in slugs:                       # truncated-slug fallback
            slug = next((s for s in slugs if len(s) >= 60 and gs.startswith(s[:60])), slug)

        cur = conn.execute(
            "UPDATE posts SET body=?, lat=?, lon=?, updated_at=datetime('now') WHERE slug=?",
            (md, lat, lon, slug))
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

    print(f"  bodies rewritten      : {updated}")
    print(f"  geo coords preserved  : {geo_found}")
    print(f"  images referenced     : {len(need)}  (copied {copied}, missing {missing})")
    conn.close()


if __name__ == "__main__":
    main()
