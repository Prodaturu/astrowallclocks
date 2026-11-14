"""Create a static, browseable snapshot of astrowallclocks.com public pages."""

from concurrent.futures import ThreadPoolExecutor, as_completed
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urlparse, unquote
import re
import subprocess
import time

ROOT = Path(__file__).resolve().parent
SITE = "https://astrowallclocks.com"
PAGES = [
    "/", "/shop/", "/product/astro-wall-clock/", "/about/", "/contact-us/",
    "/blog/", "/hello-world/", "/privacy-policy/", "/refund_returns/",
    "/terms-conditions/", "/shipping-policy/", "/track-order/", "/wishlist/",
    "/cart/", "/checkout/", "/my-account/",
]
ASSET_HOSTS = {"astrowallclocks.com"}
ASSET_EXT = re.compile(r"\.(?:css|js|mjs|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|otf|avif)(?:$|\?)", re.I)
CSS_URL = re.compile(r"url\(\s*['\"]?([^)'\"]+)", re.I)


def fetch(url):
    for attempt in range(4):
        run = subprocess.run(
            ["curl", "-L", "-sS", "--retry", "2", "--retry-all-errors", "--max-time", "45", "-w", "%{http_code}", url],
            capture_output=True,
        )
        code = run.stdout[-3:].decode("ascii", "replace") if len(run.stdout) >= 3 else "000"
        if run.returncode == 0 and code == "200":
            return run.stdout[:-3]
        time.sleep(attempt + 1)
    raise RuntimeError(f"Download failed: {url} ({code}; {run.stderr.decode(errors='replace')[:120]})")


def local_path(url):
    parsed = urlparse(url)
    path = unquote(parsed.path)
    if path.endswith("/") or not Path(path).suffix:
        path += "index.html"
    if parsed.netloc != "astrowallclocks.com":
        path = "/external/" + parsed.netloc + path
    return ROOT / path.lstrip("/")


def normalized(url, base):
    if not url or url.startswith(("data:", "blob:", "mailto:", "tel:", "javascript:", "#")):
        return None
    full = urljoin(base, url.replace("&amp;", "&"))
    parsed = urlparse(full)
    if parsed.netloc not in ASSET_HOSTS or not ASSET_EXT.search(parsed.path):
        return None
    return full


class Assets(HTMLParser):
    def __init__(self, base):
        super().__init__()
        self.base = base
        self.urls = set()

    def handle_starttag(self, tag, attrs):
        for key, value in attrs:
            if not value:
                continue
            if key in {"src", "href", "data-src", "data-lazy-src", "data-bg", "data-background", "poster"}:
                self.add(value)
            elif key in {"srcset", "data-srcset"}:
                for item in value.split(","):
                    self.add(item.strip().split(" ")[0])
            elif key == "style":
                for match in CSS_URL.findall(value):
                    self.add(match)

    def add(self, value):
        result = normalized(value, self.base)
        if result:
            self.urls.add(result)


def rewrite(text, is_html=False):
    # Keep real commerce flows pointed at the live WooCommerce site.
    protected = {}
    if is_html:
        for url in re.findall(r'https?://astrowallclocks\.com/[^\s"\'<>]*add-to-cart[^\s"\'<>]*', text):
            token = f"__LIVE_CART_LINK_{len(protected)}__"
            protected[token] = url
            text = text.replace(url, token)
    text = text.replace("https://astrowallclocks.com/", "/")
    text = text.replace("http://astrowallclocks.com/", "/")
    for source, replacement in {
        "9.png": "/assets/anuradha.svg",
        "47.png": "/assets/suresh.svg",
        "60.png": "/assets/elena.svg",
    }.items():
        text = text.replace(f"https://ecommerce.varunellanky.com/wp-content/uploads/2025/08/{source}", replacement)
    for source in ("Logo-scaled.png", "Logo-300x54.png", "Logo-1024x184.png", "Logo-768x138.png", "Logo-1536x275.png", "Logo-2048x367.png", "Logo-600x108.png"):
        text = text.replace(f"https://ecommerce.varunellanky.com/wp-content/uploads/2025/08/{source}", "/wp-content/uploads/2025/10/Logo-scaled-1.png")
    for host in ASSET_HOSTS - {"astrowallclocks.com"}:
        text = text.replace(f"https://{host}/", f"/external/{host}/")
        text = text.replace(f"http://{host}/", f"/external/{host}/")
    for token, url in protected.items():
        text = text.replace(token, url)
    return text


def save_asset(url):
    path = local_path(url)
    if path.exists() and path.stat().st_size:
        return path, None
    data = fetch(url)
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.suffix == ".css":
        css = data.decode("utf-8", "replace")
        more = {normalized(item, url) for item in CSS_URL.findall(css)}
        css = rewrite(css)
        if path.name == "post-15.css":
            css = css.replace('background-image:url("/wp-content/uploads/2025/09/BG-PNG.jpg");', "")
        data = css.encode()
    else:
        more = None
    path.write_bytes(data)
    return path, more


def main():
    avatar_dir = ROOT / "assets"
    avatar_dir.mkdir(exist_ok=True)
    for name, color in (("anuradha", "#d6b773"), ("suresh", "#b8cad0"), ("elena", "#c8a7a9")):
        (avatar_dir / f"{name}.svg").write_text(
            f'<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"><rect width="160" height="160" rx="80" fill="{color}"/><circle cx="80" cy="61" r="29" fill="#fff" opacity=".85"/><path d="M27 146c4-36 24-54 53-54s49 18 53 54" fill="#fff" opacity=".85"/></svg>',
            encoding="utf-8",
        )
    assets = set()
    for page in PAGES:
        url = SITE + page
        html = fetch(url).decode("utf-8", "replace")
        parser = Assets(url)
        parser.feed(html)
        assets.update(parser.urls)
        path = local_path(url)
        path.parent.mkdir(parents=True, exist_ok=True)
        html = rewrite(html, is_html=True)
        if page == "/":
            html = html.replace(
                "Price: &#8377; 9999/- &nbsp; &#8377; 4499/-",
                "Price: <del>&#8377; 9999/-</del> &nbsp; <strong>&#8377; 4499/-</strong>",
            )
        path.write_text(html, encoding="utf-8")
        print("page", page, len(html))

    done = set()
    while assets:
        batch = assets - done
        if not batch:
            break
        assets.clear()
        with ThreadPoolExecutor(max_workers=8) as pool:
            jobs = {pool.submit(save_asset, url): url for url in batch}
            for job in as_completed(jobs):
                url = jobs[job]
                done.add(url)
                try:
                    _, more = job.result()
                    if more:
                        assets.update(item for item in more if item)
                except Exception as exc:
                    print("FAILED", url, exc)
        print("assets downloaded", len(done))

    (ROOT / "README.md").write_text(
        "# Astro Wall Clocks static HTML copy\n\n"
        "Run `python3 -m http.server 8000` from this folder and open http://localhost:8000/.\n\n"
        "This is a snapshot of public pages. WooCommerce cart, checkout, account, forms, and WordPress editing "
        "require the live site. The Buy Now add-to-cart URL remains pointed at the live site. "
        "The local Home page restores the original light Order Now background and struck-through ₹9,999. "
        "Three testimonial avatars unavailable from an old external domain use neutral local placeholders.\n",
        encoding="utf-8",
    )


if __name__ == "__main__":
    main()
