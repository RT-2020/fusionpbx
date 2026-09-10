import os
import sys

import paramiko


def main() -> int:
    command = sys.argv[1] if len(sys.argv) > 1 else os.environ.get("FS_CMD")
    if not command:
        print("usage: remote_exec.py <command> or FS_CMD=<command>", file=sys.stderr)
        return 2

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(
        os.environ.get("FS_HOST", "192.168.2.2"),
        username=os.environ.get("FS_USER", "debian"),
        password=os.environ["FS_PASSWORD"],
        look_for_keys=False,
        allow_agent=False,
        timeout=10,
    )
    try:
        _stdin, stdout, stderr = client.exec_command(command, timeout=180)
        out = stdout.read().decode(errors="replace")
        err = stderr.read().decode(errors="replace")
        if out:
            print(out, end="")
        if err:
            print(err, end="", file=sys.stderr)
        return stdout.channel.recv_exit_status()
    finally:
        client.close()


if __name__ == "__main__":
    raise SystemExit(main())
