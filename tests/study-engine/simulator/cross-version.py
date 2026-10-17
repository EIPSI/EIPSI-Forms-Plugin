#!/usr/bin/env python3
"""Compare full pure-engine results in pinned PHP images; Docker is a test dependency only."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import pathlib
import subprocess
import time

ROOT = pathlib.Path(__file__).resolve().parents[3]
IMAGES = {
    "php74": "php@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5",
    "php83": "wordpress@sha256:4abf7a450ee477dde967584f8174d7e03221d224c4971a0c38d84e7254426e64",
}


def run(version):
    command = ["docker", "run", "--rm", "--network", "none", "--user", "1000:1000", "--entrypoint", "php", "-v", f"{ROOT}:/app:ro", "-w", "/app", IMAGES[version], "tests/run-study-engine-simulator.php", "--snapshot"]
    started = time.monotonic()
    result = subprocess.run(command, capture_output=True, check=False)
    if result.returncode:
        raise RuntimeError(f"{version}: {result.stderr.decode()} (exit {result.returncode})")
    parsed = json.loads(result.stdout)
    return version, result.stdout, parsed, time.monotonic() - started


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=pathlib.Path, required=True)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(run, IMAGES))
    for version, raw, _, _ in results:
        (args.output / f"{version}-snapshot.json").write_bytes(raw)
    if results[0][1] != results[1][1]:
        raise RuntimeError("Cross-version snapshot mismatch; artifacts retained for investigation")
    data = results[0][2]
    summary = {
        "identical_bytes": True,
        "snapshot_sha256": hashlib.sha256(results[0][1]).hexdigest(),
        "bytes": len(results[0][1]),
        "images": IMAGES,
        "runtime_seconds": {version: round(elapsed, 3) for version, _, _, elapsed in results},
        "fixtures": {name: {"protocol_hash": record["protocol_hash"], "graph_hash": record["graph_hash"], "scenarios": len(record["scenarios"])} for name, record in data.items() if "graph_hash" in record},
        "full_e2e": {name: {"nodes": len(record["instances"]), "events": len(record["events"]), "state": record["execution"]["state"]} for name, record in data["full_e2e"].items()},
        "random_vectors": len(data["random_vectors"]),
    }
    (args.output / "cross-version-summary.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2))
