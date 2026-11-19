#!/usr/bin/env python3
"""Test-only endpoint for tests/leak-probe.sh.

It receives the secret that the root vault broker injected into X-Secret and
returns it in the shapes a careless helper might 'print the variable' in: raw,
base64, URL-encoded, nested in JSON, and inside an error string. If the broker's
value-based redaction is correct, NONE of these forms survive the reply.
"""
import base64
import json
import sys
import urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer


class H(BaseHTTPRequestHandler):
    def do_GET(self):
        value = self.headers.get("X-Secret", "")
        body = json.dumps({
            "raw": value,
            "base64": base64.b64encode(value.encode()).decode(),
            "urlencoded": urllib.parse.quote(value, safe=""),
            "nested": {"password": value, "note": "printed for debugging"},
            "error": "could not use credential; var=%s" % value,
        }).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    HTTPServer(("127.0.0.1", int(sys.argv[1])), H).serve_forever()
