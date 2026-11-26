"""Read-only hash check: deployed staging code must equal the release ZIPs."""

import hashlib
import json
import os
import shlex
import subprocess
import sys
import zipfile
from pathlib import Path, PurePosixPath


STAGE = "/home/bgt9xah2o3eo/staging-astrowallclocks/wp-content"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def checked_path(name, prefix):
    path = PurePosixPath(name)
    if path.is_absolute() or ".." in path.parts or not name.startswith(prefix):
        raise SystemExit(f"Unsafe artifact path: {name}")
    return name


def main(folder):
    folder = Path(folder)
    manifest = json.loads((folder / "manifest.json").read_text())
    theme = folder / "astro-rebuild.zip"
    gateway = folder / "woocommerce_easebuzz_plugin-hardened.zip"
    if digest(theme.read_bytes()) != manifest["theme_zip_sha256"]:
        raise SystemExit("Theme ZIP hash differs from manifest")
    if digest(gateway.read_bytes()) != manifest["gateway_zip_sha256"]:
        raise SystemExit("Gateway ZIP hash differs from manifest")

    files = {}
    with zipfile.ZipFile(theme) as package:
        if set(package.namelist()) != set(manifest["theme_files"]):
            raise SystemExit("Theme file list differs from manifest")
        for name, expected in manifest["theme_files"].items():
            checked_path(name, "astro-rebuild/")
            if digest(package.read(name)) != expected:
                raise SystemExit(f"Theme file hash differs: {name}")
            files[f"{STAGE}/themes/{name}"] = expected
    gateway_count = 0
    with zipfile.ZipFile(gateway) as package:
        for name in package.namelist():
            if name.endswith("/"):
                continue
            checked_path(name, "woocommerce_easebuzz_plugin/")
            files[f"{STAGE}/plugins/{name}"] = digest(package.read(name))
            gateway_count += 1

    user = os.environ["ASTRO_STAGE_SSH_USER"]
    identity = os.environ["ASTRO_STAGE_SSH_KEY"]
    known_hosts = os.environ["ASTRO_STAGE_KNOWN_HOSTS"]
    host = os.environ.get("ASTRO_STAGE_HOST", "132.148.179.26")
    remote = "sha256sum -- " + " ".join(shlex.quote(name) for name in files)
    result = subprocess.run(
        ["ssh", "-T", "-i", identity, "-o", "IdentitiesOnly=yes", "-o", "BatchMode=yes",
         "-o", "StrictHostKeyChecking=yes", "-o", f"UserKnownHostsFile={known_hosts}",
         f"{user}@{host}", remote],
        capture_output=True, text=True, timeout=45,
    )
    if result.returncode:
        raise SystemExit("Unable to hash the staging release files")
    observed = {}
    for line in result.stdout.splitlines():
        if len(line) > 66 and line[64:66] == "  ":
            observed[line[66:]] = line[:64]
    differing = [name for name, expected in files.items() if observed.get(name) != expected]
    if differing:
        raise SystemExit(f"Staging differs from candidate in {len(differing)} file(s): "
                         + ", ".join(Path(name).name for name in differing[:8]))
    print(f"PASS staging matches candidate: {len(manifest['theme_files'])} theme files, "
          f"{gateway_count} gateway files")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("Usage: verify_stage.py dist/release-candidate/<commit>")
    main(sys.argv[1])
