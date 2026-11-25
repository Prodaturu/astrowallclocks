"""Copy only the public images referenced by the existing frontend into local WP."""

from pathlib import Path
from urllib.request import urlopen
import re
import subprocess

repo = Path(__file__).resolve().parents[1]
asset_root = repo / "dev" / "public-assets"
site = repo / "site"
refs = sorted(set(re.findall(
    r"/wp-content/uploads/[A-Za-z0-9_./-]+",
    "\n".join(page.read_text(encoding="utf-8") for page in site.rglob("*.html")),
)))

for ref in refs:
    target = asset_root / ref.removeprefix("/wp-content/uploads/")
    target.parent.mkdir(parents=True, exist_ok=True)
    with urlopen("https://astrowallclocks.com" + ref, timeout=30) as response:
        target.write_bytes(response.read())

subprocess.run([
    "docker", "compose", "-f", str(repo / "dev" / "compose.yaml"),
    "exec", "-T", "wordpress", "mkdir", "-p",
    "/var/www/html/wp-content/uploads",
], check=True)
subprocess.run([
    "docker", "compose", "-f", str(repo / "dev" / "compose.yaml"),
    "cp", str(asset_root) + "/.",
    "wordpress:/var/www/html/wp-content/uploads/",
], check=True)
subprocess.run([
    "docker", "compose", "-f", str(repo / "dev" / "compose.yaml"),
    "exec", "-T", "wordpress", "chown", "-R", "www-data:www-data",
    "/var/www/html/wp-content/uploads",
], check=True)
print(f"Copied {len(refs)} public design images to local WordPress.")
