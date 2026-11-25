"""robertpowell.com — a small blog.

Flask + SQLite. No ORM, no migrations framework; the schema is one file and the
queries are short enough to read.
"""
import io
import secrets
import os
import re
import sqlite3
import unicodedata
from datetime import datetime, timedelta, timezone
from functools import wraps
from zoneinfo import ZoneInfo

import markdown as md
import nh3
from flask import (Flask, abort, flash, g, redirect, render_template, request,
                   send_from_directory, session, url_for, Response)
from werkzeug.security import check_password_hash
from werkzeug.utils import secure_filename

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.environ.get("BLOG_DB", os.path.join(BASE, "data", "blog.db"))

app = Flask(__name__, instance_relative_config=True)
app.config.update(
    SECRET_KEY=os.environ.get("BLOG_SECRET_KEY", "dev-only-not-for-production"),
    ADMIN_PASSWORD_HASH=os.environ.get("BLOG_ADMIN_HASH", ""),
    SITE_NAME=os.environ.get("BLOG_SITE_NAME", "RobertPowell.com"),
    SITE_TAGLINE=os.environ.get("BLOG_TAGLINE", "The wonderful world of RoPo."),
    SITE_URL=os.environ.get("BLOG_SITE_URL", "https://robertpowell.com"),
    PER_PAGE=15,
    SESSION_COOKIE_SECURE=True,       # admin session cookie only over https
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE="Lax",    # not sent on cross-site POSTs
    PERMANENT_SESSION_LIFETIME=timedelta(hours=12),   # admin signs in again each day (was Flask's 31-day default)
    MAX_CONTENT_LENGTH=40 * 1024 * 1024,   # whole upload request; per-file cap is UPLOAD_MAX_BYTES
    MEDIA_DIR=os.environ.get("BLOG_MEDIA_DIR", os.path.join(os.path.dirname(DB), "media")),
    UPLOAD_MAX_BYTES=20 * 1024 * 1024,
    UPLOAD_MAX_WIDTH=1600,                 # longest side of a stored picture, in pixels
    UPLOAD_WEBP_QUALITY=80,
    WATERMARK_NAME="Robert Powell",
)


# ---------------------------------------------------------------- database

def db():
    if "db" not in g:
        g.db = sqlite3.connect(DB, detect_types=sqlite3.PARSE_DECLTYPES)
        g.db.row_factory = sqlite3.Row
        g.db.execute("PRAGMA foreign_keys=ON")
    return g.db


@app.teardown_appcontext
def close_db(exc):
    conn = g.pop("db", None)
    if conn is not None:
        conn.close()


def init_db():
    os.makedirs(os.path.dirname(DB), exist_ok=True)
    conn = sqlite3.connect(DB)
    with open(os.path.join(BASE, "app", "schema.sql")) as f:
        conn.executescript(f.read())
    conn.commit()
    conn.close()


# ---------------------------------------------------------------- helpers

def slugify(text, fallback="post"):
    text = unicodedata.normalize("NFKD", text or "")
    text = text.encode("ascii", "ignore").decode()
    text = re.sub(r"[^\w\s-]", "", text).strip().lower()
    text = re.sub(r"[-\s]+", "-", text)
    return text[:80] or fallback


def unique_slug(base, post_id=None):
    slug, n = base, 2
    while True:
        row = db().execute(
            "SELECT id FROM posts WHERE slug=? AND (? IS NULL OR id<>?)",
            (slug, post_id, post_id),
        ).fetchone()
        if row is None:
            return slug
        slug, n = f"{base}-{n}", n + 1


# Posts are Markdown. Whatever the Markdown produces is then reduced to the formatting the blog
# actually uses: paragraphs, bold, italic, strikethrough, links, images, quotes, lists, headings.
# Every other element and attribute is dropped (text inside it is kept), links may only be http,
# https or mailto, and raw HTML typed into the editor never reaches a reader (2026-11-02).
ALLOWED_TAGS = {"p", "br", "strong", "em", "del", "a", "img", "blockquote", "ul", "ol", "li",
                "h1", "h2", "h3", "h4", "h5", "h6"}
ALLOWED_ATTRS = {"a": {"href", "title"}, "img": {"src", "alt", "title"}}
URL_SCHEMES = {"http", "https", "mailto"}
MD_EXTENSIONS = ["extra", "sane_lists", "nl2br", "pymdownx.tilde"]
MD_CONFIG = {"pymdownx.tilde": {"subscript": False}}   # ~~strike~~ only, no ~subscript~


def render_markdown(text):
    html = md.markdown(text or "", extensions=MD_EXTENSIONS, extension_configs=MD_CONFIG)
    return nh3.clean(html, tags=ALLOWED_TAGS, attributes=ALLOWED_ATTRS, url_schemes=URL_SCHEMES)


def tags_for(post_id):
    return [r["name"] for r in db().execute(
        "SELECT t.name FROM tags t JOIN post_tags pt ON pt.tag_id=t.id "
        "WHERE pt.post_id=? ORDER BY t.name", (post_id,))]


def set_tags(post_id, names):
    conn = db()
    conn.execute("DELETE FROM post_tags WHERE post_id=?", (post_id,))
    for raw in names:
        name = raw.strip()
        if not name:
            continue
        conn.execute("INSERT OR IGNORE INTO tags (name) VALUES (?)", (name,))
        tag = conn.execute("SELECT id FROM tags WHERE name=?", (name,)).fetchone()
        conn.execute(
            "INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?,?)",
            (post_id, tag["id"]))


KNOWN_IPS_FILE = os.path.join(BASE, "data", "known-ips.json")


def client_ip():
    """The real caller, from behind Apache.

    mod_proxy APPENDS the peer address to any X-Forwarded-For the client sent,
    so the LAST entry is the one our own proxy wrote and the only one that can
    be trusted. Taking the first would let anyone spoof their address.
    """
    xff = request.headers.get("X-Forwarded-For", "")
    if xff:
        return xff.split(",")[-1].strip()
    return request.remote_addr or ""


def remember_ip(ip):
    """Record an address that has proved it owns the site by signing in."""
    if not ip or ip.startswith("127."):
        return
    import json as _json
    try:
        with open(KNOWN_IPS_FILE) as f:
            data = _json.load(f)
    except Exception:
        data = {}
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    entry = data.get(ip) or {"label": "You", "first_seen": now, "logins": 0}
    entry["last_seen"] = now
    entry["logins"] = entry.get("logins", 0) + 1
    data[ip] = entry
    try:
        os.makedirs(os.path.dirname(KNOWN_IPS_FILE), exist_ok=True)
        tmp = KNOWN_IPS_FILE + ".tmp"
        with open(tmp, "w") as f:
            _json.dump(data, f, indent=1, sort_keys=True)
        os.replace(tmp, KNOWN_IPS_FILE)
    except Exception:
        pass


def login_required(fn):
    @wraps(fn)
    def wrapper(*a, **kw):
        if not session.get("admin"):
            return redirect(url_for("login", next=request.path))
        return fn(*a, **kw)
    return wrapper


def safe_next(target):
    """Only ever redirect within this site: a path that starts with one '/' and no scheme or host."""
    if target and target.startswith("/") and not target.startswith("//") and "\\" not in target:
        return target
    return url_for("admin")


# --- CSRF: one random token per session, carried as a hidden field in every admin form. ---
# SameSite=Lax already stops cross-site POSTs in current browsers; this covers same-site
# subdomains and older clients. The JSON upload endpoint checks Sec-Fetch-Site instead.

def csrf_token():
    if "csrf" not in session:
        session["csrf"] = secrets.token_urlsafe(32)
    return session["csrf"]


CSRF_ENDPOINTS = {"login", "edit", "delete"}


@app.before_request
def check_csrf():
    if request.method == "POST" and request.endpoint in CSRF_ENDPOINTS:
        sent = request.form.get("csrf", "")
        held = session.get("csrf", "")
        if not held or not secrets.compare_digest(sent, held):
            abort(400, "Form expired or missing its token. Reload the page and try again.")


# Dates are stored in UTC and shown in UK time, GMT or BST as the calendar says (25 Nov 2026).
UK = ZoneInfo("Europe/London")


def to_uk(value):
    """Stored 'YYYY-MM-DD HH:MM:SS' (UTC) -> aware datetime in UK time, or None."""
    try:
        return datetime.strptime(value[:19], "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc).astimezone(UK)
    except (ValueError, TypeError):
        return None


def from_uk(text):
    """What was typed in the editor's Date box (UK time) -> stored UTC string. '' if unreadable."""
    text = (text or "").strip().replace("T", " ")
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M", "%Y-%m-%d"):
        try:
            local = datetime.strptime(text, fmt)
        except ValueError:
            continue
        if fmt == "%Y-%m-%d":
            local = local.replace(hour=12)          # a bare date means midday, safely inside that day
        return local.replace(tzinfo=UK).astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    return ""


@app.template_filter("pretty_date")
def pretty_date(value):
    if not value:
        return ""
    d = to_uk(value)
    if d is not None:
        return d.strftime("%-d %B %Y")
    try:
        return datetime.strptime(value[:10], "%Y-%m-%d").strftime("%-d %B %Y")
    except ValueError:
        return value[:10]


@app.template_filter("uk_datetime")
def uk_datetime(value):
    """For the editor's Date box and the admin list: UK wall-clock time."""
    d = to_uk(value) if value else None
    return d.strftime("%Y-%m-%d %H:%M:%S") if d else (value or "")


# ---------------------------------------------------------------- public

@app.route("/")
def index():
    page = max(1, request.args.get("page", 1, type=int))
    per = app.config["PER_PAGE"]
    rows = db().execute(
        "SELECT * FROM posts WHERE status='published' AND kind='post' "
        "ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?",
        (per + 1, (page - 1) * per)).fetchall()
    more = len(rows) > per
    posts = rows[:per]
    # Front page only: the newest post is shown in full, the rest stay a list of titles (RP, 25 Nov 2026).
    latest = latest_html = latest_tags = None
    if page == 1 and posts:
        latest, posts = posts[0], posts[1:]
        latest_html, latest_tags = render_markdown(latest["body"]), tags_for(latest["id"])
    return render_template("index.html", posts=posts, page=page, more=more, latest=latest,
                           latest_html=latest_html, latest_tags=latest_tags)


@app.route("/p/<slug>/")
def post(slug):
    row = db().execute("SELECT * FROM posts WHERE slug=? AND kind='post'",
                       (slug,)).fetchone()
    if row is None or (row["status"] != "published" and not session.get("admin")):
        abort(404)
    return render_template("post.html", post=row, html=render_markdown(row["body"]),
                           tags=tags_for(row["id"]))


@app.route("/archive/")
def archive():
    rows = db().execute(
        "SELECT substr(published_at,1,4) AS yr, COUNT(*) AS n FROM posts "
        "WHERE status='published' AND kind='post' GROUP BY yr ORDER BY yr DESC").fetchall()
    posts = db().execute(
        "SELECT slug,title,published_at FROM posts WHERE status='published' AND kind='post' "
        "ORDER BY published_at DESC, id DESC").fetchall()
    return render_template("archive.html", years=rows, posts=posts)


@app.route("/tag/<name>/")
def tag(name):
    rows = db().execute(
        "SELECT p.* FROM posts p JOIN post_tags pt ON pt.post_id=p.id "
        "JOIN tags t ON t.id=pt.tag_id WHERE t.name=? AND p.status='published' AND p.kind='post' "
        "ORDER BY p.published_at DESC", (name,)).fetchall()
    return render_template("index.html", posts=rows, page=1, more=False, heading=f"Tagged “{name}”")


RESERVED = {"p", "admin", "archive", "tag", "static", "media", "feed.xml",
            "favicon.ico", "robots.txt"}


@app.route("/<slug>/")
def page(slug):
    if slug in RESERVED:
        abort(404)
    row = db().execute("SELECT * FROM posts WHERE slug=? AND kind='page'",
                       (slug,)).fetchone()
    if row is None:
        # WordPress served posts at /<slug>/; send those old addresses to /p/<slug>/ (flip, 25 Nov 2026).
        old = db().execute("SELECT slug FROM posts WHERE slug=? AND kind='post' AND status='published'",
                           (slug,)).fetchone()
        if old is not None:
            return redirect(url_for("post", slug=old["slug"]), code=301)
    if row is None or (row["status"] != "published" and not session.get("admin")):
        abort(404)
    return render_template("page.html", post=row,
                           html=render_markdown(row["body"]))


@app.route("/feed.xml")
def feed():
    rows = db().execute(
        "SELECT * FROM posts WHERE status='published' AND kind='post' "
        "ORDER BY published_at DESC LIMIT 25").fetchall()
    xml = render_template("feed.xml", posts=rows, render=render_markdown)
    return Response(xml, mimetype="application/rss+xml")


# ---------------------------------------------------------------- admin

@app.route("/admin/login", methods=["GET", "POST"])
def login():
    if request.method == "POST":
        pw = request.form.get("password", "")
        stored = app.config["ADMIN_PASSWORD_HASH"]
        if stored and check_password_hash(stored, pw):
            session.clear()                       # fresh session on sign-in
            session["admin"] = True
            session["csrf"] = secrets.token_urlsafe(32)
            session.permanent = True
            remember_ip(client_ip())
            return redirect(safe_next(request.args.get("next")))
        flash("That password didn't work.")
    return render_template("login.html")


@app.route("/admin/logout")
def logout():
    session.clear()
    return redirect(url_for("index"))


@app.route("/admin/")
@login_required
def admin():
    rows = db().execute(
        "SELECT * FROM posts ORDER BY COALESCE(published_at, created_at) DESC, id DESC"
    ).fetchall()
    return render_template("admin.html", posts=rows)


@app.route("/admin/new", methods=["GET", "POST"])
@app.route("/admin/edit/<int:post_id>", methods=["GET", "POST"])
@login_required
def edit(post_id=None):
    conn = db()
    row = None
    if post_id:
        row = conn.execute("SELECT * FROM posts WHERE id=?", (post_id,)).fetchone()
        if row is None:
            abort(404)

    if request.method == "POST":
        title = request.form.get("title", "").strip()
        body = request.form.get("body", "")
        status = "published" if request.form.get("status") == "published" else "draft"
        tags_raw = request.form.get("tags", "")
        typed = request.form.get("published_at", "").strip()
        when = from_uk(typed)                    # the box is in UK time; storage is UTC
        if typed and not when:
            flash("Date not understood, so it was left as it was. Use YYYY-MM-DD HH:MM.")
            when = (row["published_at"] if row is not None else "") or ""
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

        if status == "published" and not when:
            when = now
        base = slugify(request.form.get("slug", "") or title, fallback="untitled")

        if row is None:
            slug = unique_slug(base)
            cur = conn.execute(
                "INSERT INTO posts (slug,title,body,status,published_at,updated_at) "
                "VALUES (?,?,?,?,?,?)", (slug, title, body, status, when or None, now))
            post_id = cur.lastrowid
        else:
            slug = unique_slug(base, row["id"])
            conn.execute(
                "UPDATE posts SET slug=?,title=?,body=?,status=?,published_at=?,updated_at=? "
                "WHERE id=?", (slug, title, body, status, when or None, now, row["id"]))
        set_tags(post_id, tags_raw.split(","))
        conn.commit()
        flash("Published." if status == "published" else "Saved as draft.")
        return redirect(url_for("edit", post_id=post_id))

    return render_template("edit.html", post=row,
                           tags=", ".join(tags_for(row["id"])) if row else "")


# ---------------------------------------------------------------- serving pictures

# Pictures are served by the app, not straight from disk by Apache, so that a picture is only public
# while a PUBLISHED post or page uses it (25 Nov 2026). Until then, and for anything uploaded but not
# used, only the signed-in admin can load it; everyone else gets the same 404 as a file that does not
# exist. The set of public pictures is rebuilt whenever the database file changes.
_MEDIA_REF = re.compile(r"/media/([A-Za-z0-9][A-Za-z0-9._/%-]*)")
_public_media = {"stamp": None, "files": frozenset()}


def public_media():
    try:
        st = os.stat(DB)
        stamp = (st.st_mtime_ns, st.st_size)
    except OSError:
        stamp = None
    if stamp is None or stamp != _public_media["stamp"]:
        from urllib.parse import unquote
        found = set()
        for (body,) in db().execute("SELECT body FROM posts WHERE status='published'"):
            for ref in _MEDIA_REF.findall(body or ""):
                found.add(unquote(ref).rstrip(").,"))
        _public_media["files"], _public_media["stamp"] = frozenset(found), stamp
    return _public_media["files"]


@app.route("/media/<path:filename>")
def media(filename):
    admin = bool(session.get("admin"))
    if not admin and filename not in public_media():
        abort(404)
    resp = send_from_directory(app.config["MEDIA_DIR"], filename, conditional=True)
    resp.headers["X-Content-Type-Options"] = "nosniff"
    if filename in public_media():
        resp.headers["Cache-Control"] = "public, max-age=86400"
    else:                                   # admin previewing a draft: never let a cache keep it
        resp.headers["Cache-Control"] = "private, no-store"
    return resp


# ---------------------------------------------------------------- image upload

IMAGE_FORMATS = {"JPEG": ".jpg", "PNG": ".png", "GIF": ".gif", "WEBP": ".webp"}


def _same_origin():
    """CSRF guard for the JSON upload endpoint: the browser must say the request is same-origin."""
    site = request.headers.get("Sec-Fetch-Site")
    if site:
        return site in ("same-origin", "none")
    origin = request.headers.get("Origin") or request.headers.get("Referer") or ""
    return origin.startswith(request.host_url.rstrip("/")) or origin.startswith(
        app.config["SITE_URL"].rstrip("/"))


def _exif_taken_at(img):
    try:
        exif = img.getexif()
        raw = exif.get(36867) or exif.get(306)          # DateTimeOriginal, else DateTime
        if raw:
            return datetime.strptime(str(raw)[:19], "%Y:%m:%d %H:%M:%S").strftime("%Y-%m-%d %H:%M:%S")
    except Exception:
        pass
    return None


WATERMARK_FONTS = ("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                   "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
                   "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")


def _watermark(img, year=None):
    """'© Robert Powell <year of upload>' in the bottom-right corner, white on a dark patch of the
    picture or near-black on a light one, with a thin outline of the opposite shade so it stays
    readable on a busy background. RGB in, RGB out."""
    from PIL import ImageDraw, ImageFont, ImageStat
    name = app.config.get("WATERMARK_NAME")
    if not name or min(img.size) < 240:                 # too small to carry it legibly
        return img
    text = f"\u00a9 {name} {year or datetime.now(UK).year}"
    size = max(13, round(max(img.size) * 0.018))
    font = None
    for path in WATERMARK_FONTS:
        try:
            font = ImageFont.truetype(path, size)
            break
        except OSError:
            continue
    if font is None:
        return img
    draw = ImageDraw.Draw(img)
    stroke = max(1, size // 12)
    l, t, r, b = draw.textbbox((0, 0), text, font=font, stroke_width=stroke)
    tw, th = r - l, b - t
    pad = round(size * 0.9)
    x, y = img.width - tw - pad - l, img.height - th - pad - t
    if x < pad:                                          # narrow picture: text would not fit
        return img
    box = (max(0, x + l - 4), max(0, y + t - 4), min(img.width, x + r + 4), min(img.height, y + b + 4))
    lum = ImageStat.Stat(img.crop(box).convert("L")).mean[0]
    fill, edge = ((255, 255, 255), (0, 0, 0)) if lum < 140 else ((20, 20, 20), (255, 255, 255))
    draw.text((x, y), text, font=font, fill=fill, stroke_width=stroke, stroke_fill=edge)
    return img


def save_image(storage, post_id=None):
    """Validate, orient, resize, strip metadata, store under MEDIA_DIR/YYYY/MM. Returns a dict."""
    from PIL import Image, ImageOps, UnidentifiedImageError
    data = storage.read()
    if not data:
        raise ValueError("empty file")
    if len(data) > app.config["UPLOAD_MAX_BYTES"]:
        raise ValueError(f"{storage.filename or 'file'} is larger than "
                         f"{app.config['UPLOAD_MAX_BYTES'] // (1024 * 1024)} MB")
    try:
        img = Image.open(io.BytesIO(data))
        img.verify()
        img = Image.open(io.BytesIO(data))
    except (UnidentifiedImageError, OSError):
        raise ValueError(f"{storage.filename or 'file'} is not a jpeg, png, gif or webp image")
    fmt = img.format
    # MPO is what phones and cameras write for a JPEG with a second embedded picture (depth map, gain
    # map, 3D pair). The first frame is the photo: keep that and store it as an ordinary JPEG.
    mpo = fmt == "MPO"
    if mpo:
        try:
            extra = [(e.get("Attribute", {}).get("MPType"), e.get("Size")) for e in (img.mpinfo.get(0xB002) or [])]
            app.logger.warning("MPO upload %s: %d frames, %s", storage.filename, getattr(img, "n_frames", 1), extra)
        except Exception:
            pass
        img.seek(0)
        fmt = "JPEG"
    if fmt not in IMAGE_FORMATS:
        raise ValueError(f"{fmt or 'unknown'} images are not accepted")
    taken = _exif_taken_at(img)
    animated = False if mpo else getattr(img, "is_animated", False)
    has_alpha = False
    if not animated:
        img = ImageOps.exif_transpose(img)
        # Cap the LONGEST side (was width only, so tall portrait photos stayed huge). 25 Nov 2026.
        w = app.config["UPLOAD_MAX_WIDTH"]
        if max(img.size) > w:
            k = w / max(img.size)
            img = img.resize((max(1, round(img.width * k)), max(1, round(img.height * k))), Image.LANCZOS)
        has_alpha = img.mode in ("RGBA", "LA", "PA") or "transparency" in img.info
        if has_alpha:
            ex = img.convert("RGBA").getchannel("A").getextrema()
            has_alpha = ex[0] < 255                     # an alpha channel that is fully opaque does not count
        # Stills are stored as WebP (about a fifth smaller than JPEG at the same look). Only a picture
        # with real transparency stays PNG; animations are kept exactly as uploaded.
        if not has_alpha:
            img = _watermark(img.convert("RGB"))
            fmt = "WEBP"
        else:
            fmt = "PNG"

    stem = slugify(os.path.splitext(storage.filename or "")[0], fallback="image")[:40]
    now = datetime.now(timezone.utc)
    rel_dir = now.strftime("%Y/%m")
    out_dir = os.path.join(app.config["MEDIA_DIR"], rel_dir)
    os.makedirs(out_dir, exist_ok=True)
    ext = IMAGE_FORMATS[fmt]
    name = f"{stem}-{secrets.token_hex(3)}{ext}"
    while os.path.exists(os.path.join(out_dir, name)):
        name = f"{stem}-{secrets.token_hex(3)}{ext}"
    path = os.path.join(out_dir, name)

    if animated:
        with open(path, "wb") as f:                     # animated gif/webp: keep frames, no re-encode
            f.write(data)
    elif fmt == "JPEG":
        img.convert("RGB").save(path, "JPEG", quality=85, optimize=True, progressive=True)
    elif fmt == "PNG":
        img.save(path, "PNG", optimize=True)            # save() without exif= drops metadata
    elif fmt == "WEBP":
        img.save(path, "WEBP", quality=app.config["UPLOAD_WEBP_QUALITY"], method=6)
    else:
        img.save(path, "GIF")
    os.chmod(path, 0o644)

    rel = f"{rel_dir}/{name}"
    conn = db()
    conn.execute("INSERT INTO media (filename, post_id, taken_at) VALUES (?,?,?)",
                 (rel, post_id, taken))
    conn.commit()
    return {"url": f"/media/{rel}", "name": name, "width": img.width, "height": img.height,
            "bytes": os.path.getsize(path), "markdown": f"![]({'/media/' + rel})"}


@app.route("/admin/upload", methods=["POST"])
def upload():
    if not session.get("admin"):
        return {"error": "not signed in"}, 401
    if not _same_origin():
        return {"error": "cross-site request refused"}, 403
    post_id = request.form.get("post_id", type=int)
    files = [f for f in request.files.getlist("images") if f and f.filename]
    if not files:
        return {"error": "no files"}, 400
    saved, errors = [], []
    for f in files[:12]:
        try:
            saved.append(save_image(f, post_id))
        except ValueError as e:
            errors.append(str(e))
    return {"saved": saved, "errors": errors}, (200 if saved else 422)


@app.errorhandler(413)
def too_large(e):
    return {"error": "upload too large"}, 413


@app.route("/admin/delete/<int:post_id>", methods=["POST"])
@login_required
def delete(post_id):
    conn = db()
    conn.execute("DELETE FROM posts WHERE id=?", (post_id,))
    conn.commit()
    flash("Deleted.")
    return redirect(url_for("admin"))


@app.errorhandler(404)
def not_found(e):
    return render_template("404.html"), 404


def _static_mtime(name):
    """Cache-buster: the file's mtime, so an edit is visible immediately."""
    try:
        return int(os.path.getmtime(os.path.join(app.static_folder, name)))
    except OSError:
        return 0


@app.context_processor
def inject():
    try:
        pages = db().execute(
            "SELECT slug,title FROM posts WHERE kind='page' AND status='published' "
            "ORDER BY title").fetchall()
    except Exception:
        pages = []
    return dict(pages=pages,
                static_v=_static_mtime,
                csrf_token=csrf_token,
                site=app.config["SITE_NAME"],
                tagline=app.config["SITE_TAGLINE"],
                is_admin=bool(session.get("admin")),
                now=datetime.now(timezone.utc))


if __name__ == "__main__":
    init_db()
    app.run(debug=True, port=8080)
