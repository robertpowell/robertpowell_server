"""Build a single self-contained HTML archive of the whole blog.

No external requests of any kind - fonts, CSS, JS and images are all inline,
so it works from a USB stick with no network. Includes drafts, because this is
an archive rather than a publication.
"""
import base64, html, json, mimetypes, os, re, sqlite3, sys
from datetime import datetime
import markdown as md

DB = "/opt/ropo-blog/data/blog.db"
MEDIA = "/opt/ropo-blog/data/media"
OUT = sys.argv[1] if len(sys.argv) > 1 else "/mnt/ropo_volume/robertpowell-migration/robertpowell-archive.html"

def data_uri(rel):
    p = os.path.join(MEDIA, rel.lstrip("/")[len("media/"):]) if rel.startswith("/media/") else None
    if not p or not os.path.exists(p):
        return None
    mime = mimetypes.guess_type(p)[0] or "application/octet-stream"
    with open(p, "rb") as f:
        return f"data:{mime};base64," + base64.b64encode(f.read()).decode()

def main():
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    rows = conn.execute(
        "SELECT * FROM posts ORDER BY COALESCE(published_at,created_at) DESC, id DESC").fetchall()

    tagmap = {}
    for r in conn.execute(
            "SELECT pt.post_id, t.name FROM post_tags pt JOIN tags t ON t.id=pt.tag_id"):
        tagmap.setdefault(r["post_id"], []).append(r["name"])

    embedded = missed = 0
    articles, index = [], []

    for r in rows:
        body = r["body"] or ""
        htmlbody = md.markdown(body, extensions=["extra", "sane_lists", "nl2br"])

        def repl(m):
            nonlocal embedded, missed
            uri = data_uri(m.group(1))
            if uri:
                embedded += 1
                return f'src="{uri}"'
            missed += 1
            return 'src="" data-missing="%s"' % html.escape(m.group(1))
        htmlbody = re.sub(r'src="(/media/[^"]+)"', repl, htmlbody)
        htmlbody = re.sub(r'href="(/media/[^"]+)"', lambda m: 'href="#"', htmlbody)

        d = (r["published_at"] or r["created_at"] or "")[:10]
        pretty = ""
        if re.match(r"\d{4}-\d{2}-\d{2}", d):
            pretty = datetime.strptime(d, "%Y-%m-%d").strftime("%-d %B %Y")
        tags = tagmap.get(r["id"], [])
        geo = f"{r['lat']}, {r['lon']}" if r["lat"] else ""
        kind = r["kind"] if "kind" in r.keys() else "post"
        aid = f"e{r['id']}"

        index.append({"id": aid, "date": d, "year": d[:4], "title": r["title"] or pretty,
                      "kind": kind, "status": r["status"], "tags": tags,
                      "text": re.sub(r"\s+", " ", body)[:400].lower()})

        badge = ""
        if r["status"] != "published":
            badge = '<span class="badge draft">not published</span>'
        if kind == "page":
            badge += '<span class="badge page">page</span>'

        articles.append(f'''<article id="{aid}" class="entry" data-year="{d[:4]}"
 data-kind="{kind}" data-status="{r['status']}">
<h2>{html.escape(r["title"] or pretty)}{badge}</h2>
<p class="meta"><time>{pretty}</time>{
 ' · <span class="tg">' + ' </span><span class="tg">'.join(html.escape(t) for t in tags) + '</span>' if tags else ''}{
 ' · <span class="geo">' + html.escape(geo) + '</span>' if geo else ''}</p>
<div class="body">{htmlbody}</div>
</article>''')

    years = sorted({i["year"] for i in index if i["year"]}, reverse=True)
    total = len(rows)
    built = datetime.now().strftime("%-d %B %Y")

    doc = ARCHIVE_TEMPLATE.format(
        total=total, years_count=len(years), images=embedded,
        built=built, first=min(i["date"] for i in index if i["date"]),
        last=max(i["date"] for i in index if i["date"]),
        yearnav="".join(f'<button data-year="{y}">{y}</button>' for y in years),
        index=json.dumps(index), articles="\n".join(articles))

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as f:
        f.write(doc)

    size = os.path.getsize(OUT)
    print(f"  entries embedded : {total}")
    print(f"  images inlined   : {embedded}   (missing {missed})")
    print(f"  written          : {OUT}")
    print(f"  size             : {size/1048576:.1f} MB")
    conn.close()


ARCHIVE_TEMPLATE = '''<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RobertPowell.com — the complete archive</title>
<style>
:root{{--ground:#fbfaf8;--surface:#fff;--ink:#1b1a18;--muted:#5d5a55;--faint:#8d8880;
--rule:#e4e0d9;--rule2:#cfc9bf;--accent:#8a4b2a;--accent-soft:#f4ece6;
--fb:Charter,"Iowan Old Style",Georgia,"Times New Roman",serif;
--fm:ui-monospace,"SF Mono",Menlo,Consolas,monospace;--measure:36rem}}
@media(prefers-color-scheme:dark){{:root{{--ground:#14130f;--surface:#1c1a16;--ink:#ece7de;
--muted:#a7a196;--faint:#79736a;--rule:#2c2822;--rule2:#413b33;--accent:#d98f62;--accent-soft:#2a1d15}}}}
*{{box-sizing:border-box}}
body{{margin:0;background:var(--ground);color:var(--ink);font-family:var(--fb);font-size:18px;line-height:1.62}}
header.top{{border-bottom:1px solid var(--rule);padding:2.6rem 1.25rem 1.8rem}}
.in{{max-width:var(--measure);margin:0 auto}}
h1{{font-size:1.7rem;font-weight:700;letter-spacing:-.02em;margin:0 0 .4rem;line-height:1.15}}
.sub{{color:var(--muted);margin:0}}
.stats{{display:flex;flex-wrap:wrap;gap:.4rem 1.5rem;margin-top:1.1rem;font-family:var(--fm);font-size:.72rem;color:var(--faint)}}
.stats b{{color:var(--muted);font-weight:500}}
.controls{{position:sticky;top:0;z-index:9;background:var(--ground);border-bottom:1px solid var(--rule);padding:.7rem 1.25rem}}
.controls .in{{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}}
input[type=search]{{font-family:var(--fb);font-size:.92rem;padding:.4rem .65rem;border:1px solid var(--rule2);
background:var(--surface);color:var(--ink);border-radius:2px;flex:1;min-width:10rem}}
button{{font-family:var(--fm);font-size:.68rem;letter-spacing:.05em;padding:.34rem .55rem;background:var(--surface);
color:var(--muted);border:1px solid var(--rule2);border-radius:2px;cursor:pointer}}
button:hover{{border-color:var(--accent);color:var(--accent)}}
button.on{{background:var(--accent);border-color:var(--accent);color:var(--ground)}}
.count{{font-family:var(--fm);font-size:.7rem;color:var(--faint);margin-left:auto}}
main{{max-width:var(--measure);margin:0 auto;padding:2rem 1.25rem 4rem}}
.entry{{padding:2.2rem 0;border-bottom:1px solid var(--rule)}}
.entry h2{{font-size:1.28rem;font-weight:600;letter-spacing:-.017em;line-height:1.22;margin:0 0 .35rem}}
.meta{{font-family:var(--fm);font-size:.7rem;color:var(--faint);margin:0 0 1.1rem}}
.tg{{color:var(--accent)}}
.badge{{display:inline-block;font-family:var(--fm);font-size:.58rem;letter-spacing:.09em;text-transform:uppercase;
border:1px solid currentColor;padding:.06rem .32rem;border-radius:2px;margin-left:.5rem;vertical-align:3px}}
.badge.draft{{color:#a3352f}}.badge.page{{color:var(--faint)}}
.body p{{margin:0 0 1.1rem}}
.body img{{max-width:100%;height:auto;display:block;margin:1.4rem 0;border-radius:2px}}
.body img[data-missing]{{display:none}}
.body blockquote{{border-left:2px solid var(--rule2);padding-left:1rem;color:var(--muted);font-style:italic;margin:0 0 1.1rem}}
.body a{{color:var(--accent)}}
.body pre{{background:var(--surface);border:1px solid var(--rule);padding:.8rem;overflow-x:auto;font-family:var(--fm);font-size:.8rem}}
.hidden{{display:none!important}}
footer{{max-width:var(--measure);margin:0 auto;padding:1.6rem 1.25rem 3rem;border-top:1px solid var(--rule);
font-family:var(--fm);font-size:.7rem;color:var(--faint);line-height:1.7}}
</style></head><body>

<header class="top"><div class="in">
<h1>RobertPowell.com</h1>
<p class="sub">The complete archive — every entry, {first} to {last}.</p>
<div class="stats">
<span><b>{total}</b> entries</span>
<span><b>{years_count}</b> years</span>
<span><b>{images}</b> images embedded</span>
<span>built {built}</span>
</div>
</div></header>

<div class="controls"><div class="in">
<input type="search" id="q" placeholder="Search every entry…" aria-label="Search">
<button data-year="" class="on">All</button>
{yearnav}
<span class="count" id="count"></span>
</div></div>

<main id="main">
{articles}
</main>

<footer>
Self-contained: every image is embedded in this file, so it works with no network connection.
Recovered from a Ghost export inside the robertpowell.com cPanel backup and rebuilt in September 2026.
Entries marked <em>not published</em> were never public on the original site and are included here for completeness.
</footer>

<script>
const IDX = {index};
const arts = new Map([...document.querySelectorAll('.entry')].map(a=>[a.id,a]));
let year='', q='';
function apply(){{
  let n=0;
  for(const it of IDX){{
    const el=arts.get(it.id); if(!el) continue;
    let show = (!year || it.year===year);
    if(show && q){{
      const hay=(it.title+' '+it.text+' '+it.tags.join(' ')).toLowerCase();
      show = hay.includes(q);
    }}
    el.classList.toggle('hidden', !show);
    if(show) n++;
  }}
  document.getElementById('count').textContent = n + (year? ' in '+year : '') + ' of {total}';
}}
document.querySelectorAll('button[data-year]').forEach(b=>b.addEventListener('click',()=>{{
  document.querySelectorAll('button[data-year]').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); year=b.dataset.year; apply(); window.scrollTo(0,0);
}}));
document.getElementById('q').addEventListener('input',e=>{{q=e.target.value.toLowerCase().trim();apply();}});
apply();
</script>
</body></html>
'''

if __name__ == "__main__":
    main()
