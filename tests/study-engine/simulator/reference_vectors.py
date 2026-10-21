#!/usr/bin/env python3
"""Independent EIPSI_RANDOM_V1 oracle. Verify committed vectors; never imports PHP."""
import hashlib
import hmac
import json
import pathlib
import struct


class Stream:
    def __init__(self, seed_hex, scope):
        self.seed = bytes.fromhex(seed_hex)
        encoded = json.dumps(scope, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        self.message = b"EIPSI_RANDOM_V1\0" + struct.pack(">I", len(encoded)) + encoded
        self.counter = 0
        self.buffer = b""
        self.rejected = 0

    def read(self, size):
        while len(self.buffer) < size:
            self.buffer += hmac.new(self.seed, self.message + struct.pack(">I", self.counter), hashlib.sha256).digest()
            self.counter += 1
        result, self.buffer = self.buffer[:size], self.buffer[size:]
        return result

    def integer(self, bound):
        limit = (2**32 // bound) * bound
        while True:
            word = struct.unpack(">I", self.read(4))[0]
            if word < limit:
                return word % bound
            self.rejected += 1


def selection(vector):
    config = vector["config"]
    stream = Stream(vector["seed_hex"], vector["scope"])
    pool = config["candidates"][:]
    if config["strategy"] == "WEIGHTED_RANDOM":
        chosen = []
        for _ in range(config["draw_count"]):
            pick = stream.integer(sum(config["weights"][candidate] for candidate in pool))
            total = 0
            for candidate in pool:
                total += config["weights"][candidate]
                if pick < total:
                    chosen.append(candidate)
                    pool.remove(candidate)
                    break
    else:
        for index in range(len(pool) - 1, 0, -1):
            other = stream.integer(index + 1)
            pool[index], pool[other] = pool[other], pool[index]
        count = 1 if config["strategy"] == "RANDOM_SELECT_ONE" else config["select_n"] if config["strategy"] == "RANDOM_SELECT_N" else len(pool)
        chosen = pool[:count]
    if config["order"] == "DEFINITION" and config["strategy"] != "RANDOM_ORDER":
        chosen = [candidate for candidate in config["candidates"] if candidate in chosen]
    return chosen


if __name__ == "__main__":
    path = pathlib.Path(__file__).with_name("random-vectors.json")
    vectors = json.loads(path.read_text())["vectors"]
    for vector in vectors:
        assert Stream(vector["seed_hex"], vector["scope"]).read(64).hex() == vector["raw_hex"], vector["id"]
        assert selection(vector) == vector["selection"], vector["id"]
        if "integer_vector" in vector:
            stream = Stream(vector["seed_hex"], vector["scope"])
            integers = vector["integer_vector"]
            assert [stream.integer(integers["bound"]) for _ in integers["values"]] == integers["values"]
            assert stream.rejected == integers["rejected_words"]
    print(f"Independent Python oracle: {len(vectors)} vectors verified")
