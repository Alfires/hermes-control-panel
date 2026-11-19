#!/usr/bin/env python3
"""panel-hostctl - the only path from an agent request to a real host action.

Runs as root on the Deck and listens on a 0660 root:deck unix socket. It has:

    ping                               liveness only
    status                             read-only, aggregated health snapshot
    scan                               read-only exploit / config scan
    cve   {query}                      read-only package/CVE lookup
    action {action,args,token,ts}      ONE allowlisted fix, HMAC-authorised

There is deliberately no "exec", no "shell" and no caller-supplied command.
Every operation is a fixed Python function; the only variable input is a
tightly validated service name, compose project, or package name. The token is
an HMAC over action+args+ts with a root-only key that the panel backend reads
at deploy time - an agent cannot mint one, and an old token cannot be replayed.

Every string that leaves this process passes through scrub(): credential-shaped
text is replaced before it can reach the agent's context.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import re
import socketserver
import stat
import subprocess
import sys
import threading
import time
import urllib.request

SOCKET = os.environ.get("PANEL_HOST_SOCKET", "/run/panel-host.sock")
KEYFILE = os.environ.get("PANEL_HOST_KEY", "/opt/hermes-control-panel/hostctl.key")
LOG = os.environ.get("PANEL_HOST_LOG", "/var/log/panel-hostctl.log")
SKEW = 120
MAX_OUT = 12000

SERVICE_RE = re.compile(r"^[A-Za-z0-9@._-]{1,80}$")
PROJECT_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,60}$")
PKG_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.+-]{0,80}$")

# The complete set of actions an agent can ever ask for. Adding one is a code
# change reviewed by the owner, never a string from a model.
ALLOWED = {
    "system_status", "security_scan", "cve_lookup",
    "fix_updates", "fix_service", "fix_docker", "fix_ssh", "reboot",
}
READ_ONLY = {"system_status", "security_scan", "cve_lookup"}


def log(msg: str) -> None:
    line = "%s %s\n" % (time.strftime("%Y-%m-%dT%H:%M:%S"), msg)
    try:
        with open(LOG, "a", encoding="utf-8") as fh:
            fh.write(line)
    except OSError:
        pass
    sys.stderr.write(line)


def key() -> bytes:
    with open(KEYFILE, "rb") as fh:
        return fh.read().strip()


def sign(action: str, args: dict, ts: str) -> str:
    msg = ("%s|%s|%s" % (action, json.dumps(args, sort_keys=True,
                                             separators=(",", ":"),
                                             ensure_ascii=False), ts)).encode()
    return hmac.new(key(), msg, hashlib.sha256).hexdigest()


def verify(action: str, args: dict, ts: str, token: str) -> tuple[bool, str]:
    try:
        when = int(ts)
    except (TypeError, ValueError):
        return False, "bad timestamp"
    if abs(time.time() - when) > SKEW:
        return False, "stale request"
    expected = sign(action, args, ts)
    if not hmac.compare_digest(expected, str(token or "")):
        return False, "bad authorization token"
    return True, "ok"


SECRET_KEYS = (r"password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|"
               r"private[_-]?key|credential|authorization|auth[_-]?header|session[_-]?key")
# (compiled pattern, replacement) - the replacement is paired explicitly so a
# group reference can never point at a group the pattern does not have.
PATTERNS = [
    (re.compile(r"(?i)(authorization\s*[:=]\s*)(bearer\s+)?[^\s\"',}]+", re.S),
     r"\1\2***CENSORED***"),
    (re.compile(r"(?i)(bearer\s+)[A-Za-z0-9._~+/=-]{8,}"),
     r"\1***CENSORED***"),
    (re.compile(r"(?i)(\"(?:%s)\"\s*:\s*)\"[^\"]*\"" % SECRET_KEYS),
     r"\1***CENSORED***"),
    (re.compile(r"(?i)\b(%s)(\s*[:=]\s*)['\" ]?([^\s'\"',;}]{3,})" % SECRET_KEYS),
     r"\1\2***CENSORED***"),
    (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----", re.S),
     "***CENSORED***"),
]


def scrub(value):
    """Never let a credential-shaped string leave this process."""
    if isinstance(value, str):
        out = value
        for pat, repl in PATTERNS:
            out = pat.sub(repl, out)
        return out[:MAX_OUT]
    if isinstance(value, dict):
        return {str(k): scrub(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [scrub(v) for v in value]
    return value


def run(argv: list[str], timeout: int = 60) -> dict:
    """Fixed argv only. Never shell=True, never a caller-built command line."""
    try:
        p = subprocess.run(argv, capture_output=True, text=True, timeout=timeout)
        out = (p.stdout or "") + (("\n" + p.stderr) if p.stderr else "")
        return {"ok": p.returncode == 0, "exit": p.returncode,
                "output": scrub(out.strip()[:MAX_OUT])}
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "command timed out"}
    except Exception as exc:  # noqa: BLE001
        return {"ok": False, "error": "%s: %s" % (exc.__class__.__name__, exc)}


# --------------------------------------------------------------- read actions
def op_status() -> dict:
    uptime = run(["/usr/bin/uptime"], 10)
    disk = run(["/usr/bin/df", "-h", "/", "/home"], 10)
    mem = run(["/usr/bin/free", "-h"], 10)
    services = run(["/usr/bin/systemctl", "--no-pager", "is-system-running"], 10)
    containers = run(["/usr/bin/docker", "ps", "--format",
                      "{{.Names}}|{{.Status}}|{{.Ports}}"], 20)
    updates = run(["/usr/bin/apt-get", "-s", "upgrade"], 60)
    count = 0
    for line in (updates.get("output") or "").splitlines():
        if line.startswith("Inst "):
            count += 1
    units = []
    for name in ("opencode-api.service", "vault-broker.service", "caddy.service",
                 "forgejo.service", "hermes.service"):
        r = run(["/usr/bin/systemctl", "is-active", name], 8)
        units.append({"unit": name, "active": r.get("output", "").strip()})
    return {"ok": True,
            "uptime": uptime.get("output", ""),
            "disk": disk.get("output", ""),
            "memory": mem.get("output", ""),
            "systemd": services.get("output", ""),
            "containers": containers.get("output", ""),
            "upgradable_packages": count,
            "services": units}


def op_scan() -> dict:
    findings: list[dict] = []

    def add(sev: str, title: str, detail: str, source: str = "hostctl") -> None:
        findings.append({"severity": sev, "title": title,
                         "detail": scrub(detail)[:2000], "source": source})

    # 1. pending security updates
    u = run(["/usr/bin/apt-get", "-s", "upgrade"], 90)
    pkgs = sorted({line.split()[1] for line in (u.get("output") or "").splitlines()
                   if line.startswith("Inst ") and len(line.split()) > 1})
    if pkgs:
        add("high" if any(p.startswith("linux-") for p in pkgs) else "medium",
            "%d package(s) have pending security/version updates" % len(pkgs),
            "Packages: " + ", ".join(pkgs[:60]))

    # 2. SSH hardening
    sshconf = run(["/usr/bin/sshd", "-T"], 15)
    cfg = {}
    for line in (sshconf.get("output") or "").splitlines():
        parts = line.split(None, 1)
        if len(parts) == 2:
            cfg[parts[0].lower()] = parts[1].lower()
    if cfg.get("permitrootlogin", "no") not in ("no", "prohibit-password", "forced-commands-only"):
        add("critical", "SSH allows root login", "PermitRootLogin=%s" % cfg.get("permitrootlogin"))
    if cfg.get("passwordauthentication", "no") != "no":
        add("high", "SSH allows password authentication", "PasswordAuthentication=yes")
    if cfg.get("x11forwarding", "no") != "no":
        add("low", "SSH X11 forwarding is enabled", "X11Forwarding=yes")
    if cfg.get("maxauthtries", "6") > "3":
        add("low", "SSH MaxAuthTries is high", "MaxAuthTries=%s" % cfg.get("maxauthtries"))

    # 3. firewall
    fw = run(["/usr/sbin/ufw", "status"], 10)
    fwout = (fw.get("output") or "").lower()
    if "inactive" in fwout or "command not found" in fwout:
        add("medium", "Host firewall is inactive or unavailable", fwout[:400])

    # 4. exposed listeners
    listeners = run(["/usr/bin/ss", "-tlnp"], 15)
    exposed = []
    for line in (listeners.get("output") or "").splitlines():
        if "0.0.0.0:" in line or ":::" in line:
            m = re.search(r":(\d+)\s", line)
            if m:
                port = int(m.group(1))
                if port not in (22, 80, 443, 4096, 4097, 8642, 9119, 9120, 3001, 18080, 18082, 18083, 18084, 18086):
                    exposed.append(str(port))
    if exposed:
        add("medium", "Unexpected listeners on all interfaces",
            "ports: " + ", ".join(sorted(set(exposed))))

    # 5. privileged docker containers
    dk = run(["/usr/bin/docker", "ps", "-q"], 20)
    if dk.get("ok") and (dk.get("output") or "").strip():
        ids = dk["output"].split()
        ins = run(["/usr/bin/docker", "inspect", "--format",
                   "{{.Name}}|{{.HostConfig.Privileged}}|{{.HostConfig.NetworkMode}}"] + ids, 25)
        for line in (ins.get("output") or "").splitlines():
            if "|true|" in line:
                add("high", "Container runs privileged", line)

    # 6. world-writable files in sensitive dirs (excluding tmp-like paths)
    ww = run(["/usr/bin/find", "/etc", "/opt", "/home/deck",
              "-xdev", "-type", "f", "-perm", "-0002", "-print"], 45)
    bad = [ln for ln in (ww.get("output") or "").splitlines()
           if ln and "/.cache" not in ln and "/node_modules" not in ln][:30]
    if bad:
        add("medium", "World-writable files in sensitive directories", "\n".join(bad))

    # 7. known critical package fingerprints (offline, curated by the owner).
    # The installer writes the rules file into the root-only secrets directory.
    fp = os.environ.get("PANEL_CRITICAL_PACKAGES",
                        "/opt/panel-secrets/critical-packages.json")
    if os.path.exists(fp):
        try:
            with open(fp, encoding="utf-8") as fh:
                rules = json.load(fh)
            for pkg, minimum in (rules or {}).items():
                pol = run(["/usr/bin/apt-cache", "policy", pkg], 10)
                m = re.search(r"Candidate:\s*(\S+)", pol.get("output") or "")
                if m and m.group(1) == "(none)":
                    add("low", "Package not available: %s" % pkg, minimum)
        except Exception as exc:  # noqa: BLE001
            log("critical package rules error: %s" % exc)

    sev_rank = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda f: sev_rank.get(f["severity"], 9))
    return {"ok": True, "findings": findings, "checked_at": int(time.time())}


def op_cve(args: dict) -> dict:
    q = str(args.get("query") or "").strip()
    if not PKG_RE.fullmatch(q) and not re.fullmatch(r"CVE-\d{4}-\d{4,7}", q, re.I):
        return {"ok": False, "error": "query must be a package name or CVE id"}
    pol = run(["/usr/bin/apt-cache", "policy", q], 12)
    installed = run(["/usr/bin/dpkg-query", "-W", "-f=${Version}", q], 12)
    result = {"ok": True, "query": q, "policy": pol.get("output", ""),
              "installed_version": installed.get("output", "")}
    # Optional online lookup; the scan still works fully offline.
    try:
        body = json.dumps({"package": {"name": q, "ecosystem": "Debian"}}).encode()
        req = urllib.request.Request("https://api.osv.dev/v1/query", data=body,
                                     headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=8) as resp:
            data = json.loads(resp.read(200000).decode("utf-8", "replace"))
        vulns = []
        for v in (data.get("vulns") or [])[:20]:
            vulns.append({"id": v.get("id"),
                          "summary": scrub(str(v.get("summary") or ""))[:400]})
        result["osv"] = vulns
    except Exception as exc:  # noqa: BLE001
        result["osv"] = []
        result["osv_note"] = "%s (offline)" % exc.__class__.__name__
    return result


# --------------------------------------------------------------- fix actions
def op_fix_updates() -> dict:
    env = os.environ.copy()
    env["DEBIAN_FRONTEND"] = "noninteractive"
    try:
        p = subprocess.run(
            ["/usr/bin/apt-get", "update", "-qq"],
            capture_output=True, text=True, timeout=300, env=env)
        p2 = subprocess.run(
            ["/usr/bin/apt-get", "-y", "dist-upgrade", "-qq",
             "-o", "Dpkg::Options::=--force-confdef",
             "-o", "Dpkg::Options::=--force-confold"],
            capture_output=True, text=True, timeout=900, env=env)
        out = (p.stdout + p.stderr + p2.stdout + p2.stderr).strip()
        return {"ok": p2.returncode == 0, "exit": p2.returncode,
                "output": scrub(out[:MAX_OUT])}
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "update timed out"}
    except Exception as exc:  # noqa: BLE001
        return {"ok": False, "error": "%s: %s" % (exc.__class__.__name__, exc)}


def op_fix_service(args: dict) -> dict:
    name = str(args.get("service") or "")
    if not SERVICE_RE.fullmatch(name):
        return {"ok": False, "error": "invalid service name"}
    exists = run(["/usr/bin/systemctl", "show", "--property=LoadState", name], 10)
    if "not-found" in (exists.get("output") or ""):
        return {"ok": False, "error": "service does not exist"}
    r1 = run(["/usr/bin/systemctl", "restart", name], 90)
    r2 = run(["/usr/bin/systemctl", "is-active", name], 15)
    return {"ok": r1.get("ok", False) and (r2.get("output") or "").strip() == "active",
            "service": name, "restart": r1, "active": (r2.get("output") or "").strip()}


def op_fix_docker(args: dict) -> dict:
    project = str(args.get("project") or "")
    if not PROJECT_RE.fullmatch(project):
        return {"ok": False, "error": "invalid compose project name"}
    found = run(["/usr/bin/docker", "compose", "-p", project, "ls", "-q"], 20)
    if project not in (found.get("output") or "").split():
        return {"ok": False, "error": "no such compose project"}
    cwd = "/opt/%s" % project
    if not os.path.isdir(cwd):
        cwd = "/opt"
    try:
        p = subprocess.run(["/usr/bin/docker", "compose", "-p", project,
                            "pull"], capture_output=True, text=True, timeout=600, cwd=cwd)
        p2 = subprocess.run(["/usr/bin/docker", "compose", "-p", project,
                             "up", "-d", "--remove-orphans"],
                            capture_output=True, text=True, timeout=600, cwd=cwd)
        out = (p.stdout + p.stderr + p2.stdout + p2.stderr).strip()
        return {"ok": p.returncode == 0 and p2.returncode == 0,
                "project": project, "output": scrub(out[:MAX_OUT])}
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "compose operation timed out"}
    except Exception as exc:  # noqa: BLE001
        return {"ok": False, "error": "%s: %s" % (exc.__class__.__name__, exc)}


def op_fix_ssh() -> dict:
    dest = "/etc/ssh/sshd_config.d/99-panel-hardening.conf"
    body = ("# Managed by panel-hostctl (owner-approved)\n"
            "PermitRootLogin no\n"
            "PasswordAuthentication no\n"
            "KbdInteractiveAuthentication no\n"
            "X11Forwarding no\n"
            "MaxAuthTries 3\n"
            "ClientAliveInterval 300\n"
            "ClientAliveCountMax 2\n")
    tmp = dest + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        fh.write(body)
    os.chmod(tmp, 0o644)
    test = run(["/usr/bin/sshd", "-t"], 15)
    if not test.get("ok"):
        os.unlink(tmp)
        return {"ok": False, "error": "sshd config test failed", "detail": test}
    os.replace(tmp, dest)
    reload_ = run(["/usr/bin/systemctl", "reload", "ssh"], 20)
    return {"ok": reload_.get("ok", False), "path": dest, "reload": reload_}


def op_reboot() -> dict:
    # Let the socket reply flush before the host goes down.
    def later() -> None:
        time.sleep(3)
        try:
            subprocess.run(["/usr/bin/systemctl", "reboot"], timeout=20)
        except Exception as exc:  # noqa: BLE001
            log("reboot failed: %s" % exc)
    threading.Thread(target=later, daemon=True).start()
    return {"ok": True, "message": "reboot scheduled"}


# ------------------------------------------------------------------ dispatch
def op_action(payload: dict) -> dict:
    action = str(payload.get("action") or "")
    args = payload.get("args") or {}
    if not isinstance(args, dict):
        return {"ok": False, "error": "args must be an object"}
    if action not in ALLOWED:
        log("refused unknown action %r" % action)
        return {"ok": False, "error": "unknown action"}

    if action not in READ_ONLY:
        ok, why = verify(action, args, str(payload.get("ts") or ""),
                         str(payload.get("token") or ""))
        if not ok:
            log("refused %s: %s from %s" % (action, why, payload.get("requestor")))
            return {"ok": False, "error": why}
        log("authorised %s args=%s requestor=%s" %
            (action, json.dumps(args)[:300], payload.get("requestor")))

    if action == "system_status":
        return op_status()
    if action == "security_scan":
        return op_scan()
    if action == "cve_lookup":
        return op_cve(args)
    if action == "fix_updates":
        return op_fix_updates()
    if action == "fix_service":
        return op_fix_service(args)
    if action == "fix_docker":
        return op_fix_docker(args)
    if action == "fix_ssh":
        return op_fix_ssh()
    if action == "reboot":
        return op_reboot()
    return {"ok": False, "error": "unreachable"}


def handle(payload: dict) -> dict:
    op = str(payload.get("op") or "")
    if op == "ping":
        return {"ok": True, "service": "panel-hostctl", "actions": sorted(ALLOWED)}
    # The convenience ops used to be completely unauthenticated: any process
    # running as the socket group could get a full host recon report (docker
    # names/ports, sshd config, firewall, listeners, pending updates). Require
    # the same signed, timestamped authorisation as an action. The PHP client
    # always uses op="action", so this only closes the side door.
    if op in {"status", "scan", "cve"}:
        ok, why = verify("op:" + op, {}, str(payload.get("ts") or ""),
                         str(payload.get("token") or ""))
        if not ok:
            log("refused %s: %s from %s" % (op, why, payload.get("requestor")))
            return {"ok": False, "error": why}
    if op == "status":
        return op_status()
    if op == "scan":
        return op_scan()
    if op == "cve":
        return op_cve(payload.get("args") or {})
    if op == "action":
        return op_action(payload)
    return {"ok": False, "error": "unknown op"}


class Handler(socketserver.StreamRequestHandler):
    def handle(self) -> None:
        buf = b""
        while b"\n" not in buf and len(buf) < 1_000_000:
            chunk = self.request.recv(65536)
            if not chunk:
                return
            buf += chunk
        try:
            payload = json.loads(buf.split(b"\n", 1)[0].decode("utf-8", "replace") or "{}")
        except json.JSONDecodeError:
            self.wfile.write(b'{"ok": false, "error": "bad json"}\n')
            return
        try:
            reply = handle(payload)
        except Exception as exc:  # noqa: BLE001
            log("unhandled error: %s" % exc)
            reply = {"ok": False, "error": "%s: %s" % (exc.__class__.__name__, exc)}
        self.wfile.write((json.dumps(scrub(reply), ensure_ascii=False) + "\n").encode())


class Server(socketserver.ThreadingUnixStreamServer):
    daemon_threads = True

    def __init__(self, path: str):
        try:
            st = os.lstat(path)
            if stat.S_ISSOCK(st.st_mode) or stat.S_ISREG(st.st_mode):
                os.unlink(path)
            elif stat.S_ISDIR(st.st_mode):
                # A stale placeholder directory must not block the socket.
                import shutil
                shutil.rmtree(path, ignore_errors=True)
        except FileNotFoundError:
            pass
        super().__init__(path, Handler)
        os.chmod(path, 0o660)
        os.chown(path, 0, int(os.environ.get("PANEL_SOCKET_GID", "1000")))  # root:deck by default


def main() -> int:
    os.makedirs(os.path.dirname(SOCKET) or ".", exist_ok=True)
    if not os.path.exists(KEYFILE):
        raise SystemExit("missing HMAC key %s" % KEYFILE)
    os.chmod(KEYFILE, 0o600)
    server = Server(SOCKET)
    log("panel-hostctl listening on %s" % SOCKET)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
