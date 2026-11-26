"""Build immutable storefront and gateway artifacts from the reviewed branch."""

import hashlib
import json
import shutil
import subprocess
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
PLUGIN = ROOT / "dev/easebuzz/.build/woocommerce_easebuzz_plugin-hardened.zip"
PLUGIN_SHA256 = "8009edce89179428ac947f8b31b8d33e74361609d25b29f45d1071127c32dd3e"


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git(*args):
    return subprocess.check_output(["git", *args], cwd=ROOT, text=True).strip()


def main():
    if git("branch", "--show-current") != "dev/local-woocommerce":
        raise SystemExit("Build only from dev/local-woocommerce")
    if git("status", "--porcelain"):
        raise SystemExit("Commit and review source changes before building a release candidate")
    if not PLUGIN.is_file() or digest(PLUGIN) != PLUGIN_SHA256:
        raise SystemExit("Reviewed Easebuzz plugin artifact missing or changed")

    commit = git("rev-parse", "HEAD")
    output = ROOT / "dist" / "release-candidate" / commit[:12]
    if output.exists():
        raise SystemExit("Candidate already exists; never overwrite tested artifacts")
    output.mkdir(parents=True)
    theme_zip = output / "astro-rebuild.zip"
    files = {}
    tracked = subprocess.check_output(
        ["git", "ls-files", "-z", "theme", "site"], cwd=ROOT
    ).decode().split("\0")
    with zipfile.ZipFile(theme_zip, "w", zipfile.ZIP_DEFLATED) as package:
        for name in sorted(filter(None, tracked)):
            source = ROOT / name
            if not source.is_file() or source.is_symlink():
                raise SystemExit(f"Unexpected theme source: {name}")
            destination = (
                "astro-rebuild/" + name.removeprefix("theme/")
                if name.startswith("theme/")
                else "astro-rebuild/design/" + name.removeprefix("site/")
            )
            content = source.read_bytes()
            info = zipfile.ZipInfo(destination, date_time=(2020, 1, 1, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            package.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED)
            files[destination] = hashlib.sha256(content).hexdigest()

    gateway_zip = output / "woocommerce_easebuzz_plugin-hardened.zip"
    shutil.copyfile(PLUGIN, gateway_zip)
    manifest = {
        "source_commit": commit,
        "branch": "dev/local-woocommerce",
        "theme_zip_sha256": digest(theme_zip),
        "gateway_zip_sha256": digest(gateway_zip),
        "theme_files": files,
        "excludes": ["wp-config.php", "database", "uploads", "credentials", "site settings"],
    }
    (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    print(f"Candidate: {output}")
    print(f"Theme: {manifest['theme_zip_sha256']}")
    print(f"Gateway: {manifest['gateway_zip_sha256']}")


if __name__ == "__main__":
    main()
