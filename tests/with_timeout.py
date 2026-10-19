#!/usr/bin/env python3
"""Runs a command with a time limit: with_timeout.py SECONDS COMMAND [ARGUMENT...]

Output (stdout and stderr together) is passed through. When the limit passes, the command and everything it started in its
process group get SIGTERM, then SIGKILL three seconds later, and the exit code is 124 (the code of GNU timeout), so tests/run.sh
can tell a hang from a failure on any machine, including macOS where there is no `timeout`."""
import os, signal, subprocess, sys

seconds = float(sys.argv[1])
child = subprocess.Popen(sys.argv[2:], stderr=subprocess.STDOUT, start_new_session=True)

def stop_group(sig):
    try:
        os.killpg(child.pid, sig)
    except ProcessLookupError:
        pass

# If this helper itself is stopped (Ctrl-C, a parent timeout), the test goes with it.
for s in (signal.SIGTERM, signal.SIGINT):
    signal.signal(s, lambda n, f: (stop_group(signal.SIGKILL), sys.exit(128 + n)))
try:
    sys.exit(child.wait(timeout=seconds))
except subprocess.TimeoutExpired:
    stop_group(signal.SIGTERM)
    try:
        child.wait(timeout=3)
    except subprocess.TimeoutExpired:
        pass
    stop_group(signal.SIGKILL)
    child.wait()
    sys.exit(124)
