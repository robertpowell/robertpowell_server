"""Remove dead links and images from post bodies.

Policy, deliberately conservative:
  * dead IMAGE  -> remove the image entirely (it renders as a broken icon)
  * dead LINK   -> unwrap to plain text, keeping the words the link wrapped
  * 403 from a datacenter IP on a page we cannot otherwise verify -> LEFT ALONE
    (Cloudflare and similar block DO addresses; that is not proof of death)
"""
import json, re, sqlite3, sys

DB = "/opt/ropo-blog/data/blog.db"
REPORT = "/tmp/claude-0/-root/12da5823-96e3-4885-99c8-adb42617ecbd/scratchpad/linkreport.json"

# Verified gone: 410 Gone, or 404, or a 403 image whose asset is provably dead.
DEAD_PATTERNS = [
    r"^http://static\.flickr\.com/",                  # 410 Gone
    r"^https?://www\.flickr\.com/photos/robertpowell/\d+/",  # 403; their images are 410
    r"^https?://robertpowell\.files\.wordpress\.com/",       # 403 images -> broken on page
    r"^http://img\.ly/",                              # 404
    r"^http://read\.bi/",                             # 404
]
KEEP_BUT_FLAG = [r"^https?://www\.inc\.com/"]         # 403 only; may well be alive

dead_rx = re.compile("|".join(DEAD_PATTERNS))


def is_dead(url):
    return bool(dead_rx.match(url))


def fix(body):
    b = body or ""
    removed_img = removed_link = 0

    # 1. images with dead sources - drop the whole image
    def img_sub(m):
        nonlocal removed_img
        if is_dead(m.group(2)):
            removed_img += 1
            return ""
        return m.group(0)
    b = re.sub(r'!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)', img_sub, b)

    # 2. links with dead targets - keep the text, drop the link
    def link_sub(m):
        nonlocal removed_link
        text, url = m.group(1), m.group(2)
        if is_dead(url):
            removed_link += 1
            return text
        return m.group(0)
    b = re.sub(r'(?<!!)\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)', link_sub, b)

    # 3. bare autolinks that are dead
    def auto_sub(m):
        nonlocal removed_link
        if is_dead(m.group(1)):
            removed_link += 1
            return ""
        return m.group(0)
    b = re.sub(r'<(https?://[^>\s]+)>', auto_sub, b)

    # tidy the holes left behind
    b = re.sub(r"^[ \t]*[\.\,\;]?[ \t]*$", "", b, flags=re.M)
    b = re.sub(r"\n{3,}", "\n\n", b)
    b = re.sub(r"[ \t]{2,}", " ", b)
    b = re.sub(r"[ \t]+\n", "\n", b)
    return b.strip(), removed_img, removed_link


def main():
    apply = "--apply" in sys.argv
    conn = sqlite3.connect(DB)
    rows = conn.execute("SELECT id,slug,title,body FROM posts").fetchall()
    tot_i = tot_l = touched = 0
    emptied = []

    for pid, slug, title, body in rows:
        nb, ri, rl = fix(body)
        if ri or rl:
            touched += 1
            tot_i += ri
            tot_l += rl
            if not nb.strip():
                emptied.append((slug, title))
            if apply:
                conn.execute("UPDATE posts SET body=?, updated_at=datetime('now') WHERE id=?",
                             (nb, pid))
    if apply:
        conn.commit()

    print(f"  posts touched   : {touched}")
    print(f"  images removed  : {tot_i}")
    print(f"  links unwrapped : {tot_l}")
    print(f"  {'APPLIED' if apply else 'DRY RUN - nothing written'}")
    if emptied:
        print(f"\n  posts left with NO body ({len(emptied)}):")
        for s, t in emptied:
            print(f"    /p/{s[:44]:46} {(t or '(untitled)')[:34]}")
    conn.close()


if __name__ == "__main__":
    main()
