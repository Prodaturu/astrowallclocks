"""Build the public HTML snapshot for GitHub Pages project hosting."""

from pathlib import Path
import re

import mirror


HERE = Path(__file__).resolve().parent
DIST = HERE / "dist"
PREFIX = "/astrowallclocks"


def add_pages_prefix(text):
    # GitHub project Pages uses /astrowallclocks/, while the local mirror uses /.
    text = re.sub(r'(["\'])/(?!/|astrowallclocks/)', rf'\1{PREFIX}/', text)
    text = re.sub(r'(,\s*)/(wp-content|wp-includes|assets)/', rf'\1{PREFIX}/\2/', text)
    text = text.replace('url(/wp-content/', f'url({PREFIX}/wp-content/')
    text = text.replace('url(/wp-includes/', f'url({PREFIX}/wp-includes/')
    for path in ("wp-json/", "xmlrpc.php", "feed/"):
        text = text.replace(f"{PREFIX}/{path}", f"https://astrowallclocks.com/{path}")
    return text


def main():
    DIST.mkdir(parents=True, exist_ok=True)
    mirror.ROOT = DIST
    mirror.main()
    for file in DIST.rglob("*"):
        if file.is_file() and file.suffix.lower() in {".html", ".css"}:
            file.write_text(add_pages_prefix(file.read_text(encoding="utf-8")), encoding="utf-8")
    (DIST / ".nojekyll").touch()
    assert (DIST / "index.html").exists()


if __name__ == "__main__":
    main()
