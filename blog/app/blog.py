"""robertpowell.com — a small blog.

Flask + SQLite. No ORM, no migrations framework; the schema is one file and the
queries are short enough to read.
"""
import os
import re
import sqlite3
import unicodedata
from datetime import datetime, timezone
from functools import wraps

import markdown as md
from flask import (Flask, abort, flash, g, redirect, render_template, request,
                   session, url_for, Response)
from werkzeug.security import check_password_hash

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.environ.get("BLOG_DB", os.path.join(BASE, "data", "blog.db"))

app = Flask(__name__, instance_relative_config=True)
app.config.update(
    SECRET_KEY=os.environ.get("BLOG_SECRET_KEY", "dev-only-not-for-production"),
    ADMIN_PASSWORD_HASH=os.environ.get("BLOG_ADMIN_HASH", ""),
    SITE_NAME=os.environ.get("BLOG_SITE_NAME", "RobertPowell.com"),
    SITE_TAGLINE=os.environ.get("BLOG_TAGLINE", "The wonderful world of RoPo."),
    SITE_URL=os.environ.get("BLOG_SITE_URL", "https://new.robertpowell.com"),
    PER_PAGE=15,
    SESSION_COOKIE_SECURE=True,       # admin session cookie only over https
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE="Lax",    # not sent on cross-site POSTs
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


def render_markdown(text):
    return md.markdown(text or "", extensions=["extra", "sane_lists", "nl2br"])


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


@app.template_filter("pretty_date")
def pretty_date(value):
    if not value:
        return ""
    try:
        return datetime.strptime(value[:10], "%Y-%m-%d").strftime("%-d %B %Y")
    except ValueError:
        return value[:10]


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
    return render_template("index.html", posts=rows[:per], page=page, more=more)


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
            session["admin"] = True
            session.permanent = True
            remember_ip(client_ip())
            return redirect(request.args.get("next") or url_for("admin"))
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
        when = request.form.get("published_at", "").strip()
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
                site=app.config["SITE_NAME"],
                tagline=app.config["SITE_TAGLINE"],
                is_admin=bool(session.get("admin")),
                now=datetime.now(timezone.utc))


if __name__ == "__main__":
    init_db()
    app.run(debug=True, port=8080)
