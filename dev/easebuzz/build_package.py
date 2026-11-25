"""Build a staging candidate from the pinned official Easebuzz ZIP; no deployment."""

import hashlib
import sys
import tempfile
import zipfile
from pathlib import Path

from harden_plugin import main as harden


OFFICIAL_SHA256 = "8ec441115adc9244fad01e0edc6ff191cc4168eb722f8045c6a91aa6df438811"
ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT / ".build" / "woocommerce_easebuzz_plugin-hardened.zip"


def main(archive):
    archive = Path(archive)
    if hashlib.sha256(archive.read_bytes()).hexdigest() != OFFICIAL_SHA256:
        raise RuntimeError("Unreviewed official ZIP; review source changes first")
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        with zipfile.ZipFile(archive) as package:
            package.extractall(root / "official")
        hardened = root / "hardened" / "woocommerce_easebuzz_plugin"
        harden(root / "official" / "woocommerce_easebuzz_plugin", hardened)
        OUTPUT.parent.mkdir(exist_ok=True)
        with zipfile.ZipFile(OUTPUT, "w", zipfile.ZIP_DEFLATED) as package:
            for file in hardened.rglob("*"):
                if file.is_file():
                    package.write(file, file.relative_to(hardened.parent))
    print(f"Prepared local staging candidate: {OUTPUT}")
    print(f"SHA256: {hashlib.sha256(OUTPUT.read_bytes()).hexdigest()}")


if __name__ == "__main__":
    main(sys.argv[1])
