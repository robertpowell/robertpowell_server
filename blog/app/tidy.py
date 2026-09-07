"""Final tidy pass over titles and bodies.

Residue the HTML repair could not catch, because the source markup had
attribute names sitting where values should be:
  * HTML entities left in titles          (&#8217; -> ')
  * alt text that is an attribute name    (alt="width", alt="style", ...)
  * a bare lat/lon pair left in one body
"""
import html as htmllib
import re, sqlite3

DB = "/opt/ropo-blog/data/blog.db"
JUNK_ALT = {"style", "width", "height", "title", "image", "class", "border",
            "alt", "src", "div id", "p", "a", "br", "img", "div", "span"}


def clean_title(t):
    t = htmllib.unescape(t or "")
    return re.sub(r"\s+", " ", t).strip()


def clean_body(b):
    b = b or ""
    b = htmllib.unescape(b)

    def alt(m):
        text = m.group(1).strip()
        return "![]" if text.lower() in JUNK_ALT else m.group(0)
    b = re.sub(r"!\[([^\]]*)\]", alt, b)

    # a bare "51.2333701-0.7918652" left behind by a stripped geo div
    b = re.sub(r"(?<![\d.])-?\d{1,3}\.\d{5,}-\d{1,3}\.\d{5,}(?![\d.])", "", b)
    b = re.sub(r"[ \t]+\n", "\n", b)
    b = re.sub(r"\n{3,}", "\n\n", b)
    return b.strip()


def main():
    conn = sqlite3.connect(DB)
    rows = conn.execute("SELECT id,title,body FROM posts").fetchall()
    t_fixed = b_fixed = 0
    for pid, title, body in rows:
        nt, nb = clean_title(title), clean_body(body)
        if nt != (title or ""):
            conn.execute("UPDATE posts SET title=? WHERE id=?", (nt, pid))
            t_fixed += 1
        if nb != (body or ""):
            conn.execute("UPDATE posts SET body=? WHERE id=?", (nb, pid))
            b_fixed += 1
    conn.commit()
    print(f"  titles cleaned : {t_fixed}")
    print(f"  bodies cleaned : {b_fixed}")
    conn.close()


if __name__ == "__main__":
    main()
