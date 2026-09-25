"""Minimal SSH server that answers 3PAR CLI commands from tests/fixtures.

Used by the test-suite to exercise the real paramiko code path of
ssmc_collect.py (parallel channels, timeouts, auth failures) without an array.
"""

import os
import socket
import threading
import time

import paramiko

FIXTURES = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures')


class _Server(paramiko.ServerInterface):
    def __init__(self, owner):
        self.owner = owner

    def check_auth_password(self, username, password):
        if (username, password) == (self.owner.user, self.owner.password):
            return paramiko.AUTH_SUCCESSFUL
        return paramiko.AUTH_FAILED

    def get_allowed_auths(self, username):
        return 'password'

    def check_channel_request(self, kind, chanid):
        return paramiko.OPEN_SUCCEEDED if kind == 'session' else \
            paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED

    def check_channel_exec_request(self, channel, command):
        threading.Thread(target=self.owner.answer, args=(channel, command.decode()),
                         daemon=True).start()
        return True


class Fake3PAR:
    def __init__(self, user='3paradm', password='s3cret', delays=None, fixtures=FIXTURES, port=0):
        self.user, self.password = user, password
        self.delays = delays or {}
        self.fixtures = fixtures
        self.commands = []
        self.connections = 0
        self.host_key = paramiko.RSAKey.generate(2048)
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(('127.0.0.1', port))
        self.sock.listen(16)
        self.port = self.sock.getsockname()[1]
        self._stop = False
        threading.Thread(target=self._accept, daemon=True).start()

    def _accept(self):
        while not self._stop:
            try:
                client, _ = self.sock.accept()
            except OSError:
                return
            self.connections += 1
            t = paramiko.Transport(client)
            t.add_server_key(self.host_key)
            try:
                t.start_server(server=_Server(self))
            except paramiko.SSHException:
                continue

    def answer(self, channel, command):
        from ssmc_collect import FixtureRunner  # renders {{now+Nd}} placeholders
        self.commands.append(command)
        # Let the transport acknowledge the exec request before we reply and close,
        # as sshd does; closing first makes the client see "Channel closed".
        time.sleep(0.05 + self.delays.get(command, 0))
        try:
            channel.sendall(FixtureRunner(self.fixtures).run(command).encode())
            channel.send_exit_status(0)
        except RuntimeError:
            channel.sendall_stderr(('Invalid command %s\n' % command).encode())
            channel.send_exit_status(1)
        except OSError:
            return
        channel.close()

    def close(self):
        self._stop = True
        self.sock.close()


if __name__ == '__main__':
    # Manual runs: python3 tests/fake_3par.py [port]
    import sys
    sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    srv = Fake3PAR(port=int(sys.argv[1]) if len(sys.argv) > 1 else 2222, delays={'showvv': 4})
    print('fake 3PAR on 127.0.0.1:%d (3paradm / s3cret)' % srv.port, flush=True)
    while True:
        time.sleep(3600)
