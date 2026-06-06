#!/usr/bin/env python3
import argparse
import hashlib
import socket
import subprocess
import sys
from pathlib import Path


class RouterOS:
    def __init__(self, host, port, user, password, timeout=20):
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.sock = socket.create_connection((host, port), timeout=timeout)
        self.sock.settimeout(timeout)

    def close(self):
        self.sock.close()

    def _write_len(self, length):
        if length < 0x80:
            self.sock.sendall(bytes([length]))
        elif length < 0x4000:
            self.sock.sendall(bytes([(length >> 8) | 0x80, length & 0xFF]))
        elif length < 0x200000:
            self.sock.sendall(bytes([(length >> 16) | 0xC0, (length >> 8) & 0xFF, length & 0xFF]))
        elif length < 0x10000000:
            self.sock.sendall(bytes([(length >> 24) | 0xE0, (length >> 16) & 0xFF, (length >> 8) & 0xFF, length & 0xFF]))
        else:
            self.sock.sendall(bytes([0xF0, (length >> 24) & 0xFF, (length >> 16) & 0xFF, (length >> 8) & 0xFF, length & 0xFF]))

    def _read_len(self):
        first = self.sock.recv(1)
        if not first:
            raise EOFError("RouterOS API connection closed")
        first = first[0]
        if (first & 0x80) == 0:
            return first
        if (first & 0xC0) == 0x80:
            return ((first & ~0xC0) << 8) + self.sock.recv(1)[0]
        if (first & 0xE0) == 0xC0:
            data = self.sock.recv(2)
            return ((first & ~0xE0) << 16) + (data[0] << 8) + data[1]
        if (first & 0xF0) == 0xE0:
            data = self.sock.recv(3)
            return ((first & ~0xF0) << 24) + (data[0] << 16) + (data[1] << 8) + data[2]
        data = self.sock.recv(4)
        return (data[0] << 24) + (data[1] << 16) + (data[2] << 8) + data[3]

    def _write_word(self, word):
        data = word.encode()
        self._write_len(len(data))
        self.sock.sendall(data)

    def _read_word(self):
        length = self._read_len()
        if length == 0:
            return ""
        return self.sock.recv(length).decode(errors="replace")

    def command(self, path, **attrs):
        self._write_word(path)
        for key, value in attrs.items():
            if value is not None:
                if key.startswith("?"):
                    self._write_word(f"{key}={value}")
                else:
                    self._write_word(f"={key}={value}")
        self._write_word("")

        replies = []
        sentence = []
        while True:
            word = self._read_word()
            if word == "":
                if sentence:
                    replies.append(sentence)
                    if sentence[0] in ("!done", "!fatal"):
                        return replies
                    sentence = []
                continue
            sentence.append(word)

    def login(self):
        replies = self.command("/login", name=self.user, password=self.password)
        if any(sentence and sentence[0] == "!done" for sentence in replies) and not any(
            sentence and sentence[0] == "!trap" for sentence in replies
        ):
            return

        challenge_replies = self.command("/login")
        challenge = None
        for sentence in challenge_replies:
            for word in sentence:
                if word.startswith("=ret="):
                    challenge = word[5:]
        if not challenge:
            traps = [sentence_to_dict(s).get("message", str(s)) for s in replies if s and s[0] == "!trap"]
            detail = "; ".join(traps) if traps else "no legacy challenge was returned"
            raise RuntimeError(f"RouterOS login failed: {detail}")
        digest = hashlib.md5(b"\x00" + self.password.encode() + bytes.fromhex(challenge)).hexdigest()
        replies = self.command("/login", name=self.user, response="00" + digest)
        if not any(sentence and sentence[0] == "!done" for sentence in replies):
            raise RuntimeError(f"RouterOS legacy login failed: {replies}")


def sentence_to_dict(sentence):
    item = {}
    for word in sentence[1:]:
        if word.startswith("="):
            key, _, value = word[1:].partition("=")
            item[key] = value
    return item


def require_done(replies, action):
    traps = [sentence_to_dict(s).get("message", str(s)) for s in replies if s and s[0] == "!trap"]
    if traps:
        raise RuntimeError(f"{action} failed: {'; '.join(traps)}")
    if not any(s and s[0] == "!done" for s in replies):
        raise RuntimeError(f"{action} did not finish cleanly: {replies}")


def command_trap_messages(replies):
    return [sentence_to_dict(s).get("message", str(s)) for s in replies if s and s[0] == "!trap"]


def curl_upload(archive, host, user, password, remote_name):
    url = f"ftp://{host}/{remote_name}"
    cmd = [
        "curl",
        "--fail",
        "--show-error",
        "--user",
        f"{user}:{password}",
        "--upload-file",
        str(archive),
        url,
    ]
    subprocess.run(cmd, check=True)


def main():
    parser = argparse.ArgumentParser(description="Copy a Docker archive to RouterOS and add it as a container.")
    parser.add_argument("--host", default="10.8.0.9")
    parser.add_argument("--api-port", type=int, default=8728)
    parser.add_argument("--user", default="admin")
    parser.add_argument("--password", required=True)
    parser.add_argument("--archive", required=True, type=Path)
    parser.add_argument("--remote-name")
    parser.add_argument("--container-name", default="mikhmon")
    parser.add_argument("--root-dir", default="mikhmon-root")
    parser.add_argument("--interface", help="Optional RouterOS veth interface name for the container.")
    parser.add_argument("--cmd", default="-S 0.0.0.0:80 -t /src/src/", help="Container command arguments for the PHP entrypoint.")
    parser.add_argument("--skip-upload", action="store_true")
    args = parser.parse_args()

    archive = args.archive.resolve()
    if not archive.exists():
        raise SystemExit(f"Archive not found: {archive}")
    remote_name = args.remote_name or archive.name

    if not args.skip_upload:
        print(f"Uploading {archive} to ftp://{args.host}/{remote_name}")
        curl_upload(archive, args.host, args.user, args.password, remote_name)

    ros = RouterOS(args.host, args.api_port, args.user, args.password)
    try:
        ros.login()
        resources = ros.command("/system/resource/print")
        for sentence in resources:
            if sentence and sentence[0] == "!re":
                info = sentence_to_dict(sentence)
                print(f"RouterOS: {info.get('version', 'unknown')} on {info.get('architecture-name', 'unknown')}")

        files = ros.command("/file/print", **{"?name": remote_name})
        if not any(sentence and sentence[0] == "!re" for sentence in files):
            raise RuntimeError(f"{remote_name} is not visible in /file after upload")

        attrs = {
            "file": remote_name,
            "root-dir": args.root_dir,
            "name": args.container_name,
            "logging": "yes",
            "start-on-boot": "yes",
        }
        if args.interface:
            attrs["interface"] = args.interface
        if args.cmd:
            attrs["cmd"] = args.cmd

        print(f"Adding container {args.container_name} from {remote_name}")
        add_replies = ros.command("/container/add", **attrs)
        traps = command_trap_messages(add_replies)
        if any("unknown parameter name" in trap for trap in traps):
            attrs.pop("name", None)
            add_replies = ros.command("/container/add", **attrs)
        require_done(add_replies, "/container/add")

        containers = ros.command("/container/print", **{"?name": args.container_name})
        container_id = None
        for sentence in containers:
            if sentence and sentence[0] == "!re":
                item = sentence_to_dict(sentence)
                container_id = item.get(".id")
                print(f"Container: id={container_id} status={item.get('status', 'unknown')}")
        if container_id:
            require_done(ros.command("/container/start", **{".id": container_id}), "/container/start")
            print(f"Started container {args.container_name}")
    finally:
        ros.close()


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        sys.exit(1)
