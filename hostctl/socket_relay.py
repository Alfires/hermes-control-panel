#!/usr/bin/env python3
"""Stable unix sockets for the panel container.

A bind-mounted socket file points at an inode that changes when a broker
restarts, so the container ends up talking to a dead socket. Mounting a
*directory* does not have that problem. This relay listens on two stable
sockets inside /opt/panel-sockets and forwards each connection to the real
broker, reconnecting every time - so broker restarts are invisible to the panel.

Only the two socket files are exposed; no host filesystem is granted.
"""
from __future__ import annotations

import os
import shutil
import socket
import socketserver
import stat
import threading

SOCKET_DIR = os.environ.get("RELAY_DIR", "/opt/panel-sockets")
RELAYS = [
    ("vault.sock", os.environ.get("RELAY_VAULT", "/run/vault-broker.sock")),
    ("hostctl.sock", os.environ.get("RELAY_HOSTCTL", "/run/panel-host.sock")),
]
GROUP = 1000  # deck - the only group allowed to connect


class Handler(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        try:
            with socket.socket(socket.AF_UNIX) as upstream:
                upstream.connect(self.server.target)  # type: ignore[attr-defined]

                def pump(src: socket.socket, dst: socket.socket) -> None:
                    try:
                        while True:
                            data = src.recv(65536)
                            if not data:
                                break
                            dst.sendall(data)
                    except OSError:
                        pass
                    try:
                        dst.shutdown(socket.SHUT_WR)
                    except OSError:
                        pass

                a = threading.Thread(target=pump, args=(self.request, upstream), daemon=True)
                b = threading.Thread(target=pump, args=(upstream, self.request), daemon=True)
                a.start(); b.start(); a.join(); b.join()
        except OSError:
            # The target broker is restarting; the next connection will work.
            pass


class Server(socketserver.ThreadingUnixStreamServer):
    daemon_threads = True

    def __init__(self, path: str, target: str):
        self.target = target
        try:
            st = os.lstat(path)
            if stat.S_ISSOCK(st.st_mode) or stat.S_ISREG(st.st_mode):
                os.unlink(path)
            elif stat.S_ISDIR(st.st_mode):
                shutil.rmtree(path, ignore_errors=True)
        except FileNotFoundError:
            pass
        super().__init__(path, Handler)
        os.chmod(path, 0o660)
        os.chown(path, 0, GROUP)


def serve(name: str, target: str) -> None:
    path = os.path.join(SOCKET_DIR, name)
    Server(path, target).serve_forever()


def main() -> None:
    os.makedirs(SOCKET_DIR, mode=0o755, exist_ok=True)
    threads = [threading.Thread(target=serve, args=(n, t), daemon=True)
               for n, t in RELAYS]
    for t in threads:
        t.start()
    for t in threads:
        t.join()


if __name__ == "__main__":
    main()
