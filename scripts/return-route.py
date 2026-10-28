#!/usr/bin/env python3
"""Manage one narrowly scoped IPv4 return-routing policy. Never flush/replace routes."""
import argparse
import ipaddress
import json
import os
import pathlib
import re
import stat
import subprocess
import sys
import tempfile

CONFIG = pathlib.Path('/opt/mailhub/runtime/network/return-route.json')
STATE = CONFIG.with_name('return-route.applied.json')
FIELDS = {'gateway', 'device', 'proxy_ip', 'port', 'table', 'local_priority', 'gateway_priority'}
PRIVATE_NETS = tuple(ipaddress.ip_network(n) for n in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'))


class PolicyError(RuntimeError):
    pass


def validate_config(value):
    if not isinstance(value, dict) or set(value) != FIELDS:
        raise PolicyError('Configuration must contain exactly the documented seven fields.')
    result = dict(value)
    for key in ('gateway', 'proxy_ip'):
        try:
            if not isinstance(value[key], str):
                raise ValueError()
            address = ipaddress.IPv4Address(value[key])
        except ValueError:
            raise PolicyError(key + ' must be an IPv4 address.') from None
        if not any(address in network for network in PRIVATE_NETS):
            raise PolicyError(key + ' must be an RFC1918 private address.')
        result[key] = str(address)
    if result['gateway'] == result['proxy_ip']:
        raise PolicyError('Gateway and proxy address must differ.')
    if (not isinstance(value['device'], str)
            or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,14}', value['device'])
            or value['device'] == 'lo'):
        raise PolicyError('Invalid interface name.')
    for key, low, high in (('port', 1, 65535), ('table', 1000, 2147483647),
                           ('local_priority', 1000, 32765), ('gateway_priority', 1000, 32765)):
        if type(value[key]) is not int or not low <= value[key] <= high:
            raise PolicyError(key + ' must be an integer in its documented range.')
    if value['local_priority'] >= value['gateway_priority']:
        raise PolicyError('local_priority must be lower than gateway_priority.')
    return result


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise PolicyError('Duplicate JSON field: ' + key)
        result[key] = value
    return result


def read_secure(path, optional=False):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except FileNotFoundError:
        if optional:
            return None
        raise PolicyError('Missing configuration file: ' + str(path)) from None
    with os.fdopen(fd, 'r', encoding='utf-8') as handle:
        info = os.fstat(handle.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > 4096):
            raise PolicyError('Configuration/state must be a root-owned regular file, mode 0600, at most 4096 bytes.')
        try:
            return validate_config(json.load(handle, object_pairs_hook=unique_object))
        except (ValueError, UnicodeError):
            raise PolicyError('Invalid configuration JSON.') from None


def check_directories():
    for path in (pathlib.Path('/opt/mailhub'), CONFIG.parent.parent, CONFIG.parent):
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise PolicyError('Deployment directories must be root-owned, non-symlink directories without group/other write access.')
    if stat.S_IMODE(CONFIG.parent.stat().st_mode) != 0o700:
        raise PolicyError('runtime/network must have mode 0700.')


def write_state(config):
    # Parent directory is root-only. Atomic replacement never follows a symlink.
    fd, name = tempfile.mkstemp(prefix='.return-route.', dir=STATE.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            json.dump(config, handle, sort_keys=True, indent=2)
            handle.write('\n')
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(name, STATE)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def table_id(value):
    aliases = {'local': 255, 'main': 254, 'default': 253, 'unspec': 0}
    if type(value) is int:
        return value
    if isinstance(value, str):
        if value in aliases:
            return aliases[value]
        if re.fullmatch(r'[0-9]+', value):
            return int(value)
    return None


def one_port(value, expected):
    if type(value) is int:
        return value == expected
    if isinstance(value, str):
        return value == str(expected) or value == f'{expected}-{expected}'
    return False


def rule_matches(rule, config, kind):
    keys = {'priority', 'src', 'ipproto', 'sport', 'table'}
    if kind == 'local':
        keys.add('suppress_prefixlen')
    # Extra selectors, flags, actions and protocols must not be silently adopted.
    if set(rule) != keys:
        return False
    try:
        source = ipaddress.ip_network(rule['src'], strict=False)
    except (ValueError, TypeError):
        return False
    wanted_priority = config['local_priority' if kind == 'local' else 'gateway_priority']
    wanted_table = 254 if kind == 'local' else config['table']
    return (type(rule['priority']) is int and rule['priority'] == wanted_priority
            and source == ipaddress.ip_network(config['proxy_ip'] + '/32')
            and rule['ipproto'] in ('tcp', 6, '6', 'ipproto-6')
            and one_port(rule['sport'], config['port'])
            and table_id(rule['table']) == wanted_table
            and (kind != 'local' or type(rule['suppress_prefixlen']) is int
                 and rule['suppress_prefixlen'] == 0))


def route_matches(route, config):
    required = {'dst', 'gateway', 'dev', 'flags'}
    if not required <= set(route) or set(route) - required - {'table', 'protocol', 'scope', 'type'}:
        return False
    return (route['dst'] == 'default' and route['gateway'] == config['gateway']
            and route['dev'] == config['device'] and route['flags'] == ['onlink']
            and ('table' not in route or table_id(route['table']) == config['table'])
            and route.get('protocol', 'boot') in ('boot', 3, '3')
            and route.get('scope', 'global') in ('global', 0, '0')
            and route.get('type', 'unicast') in ('unicast', 1, '1'))


def inspect_snapshot(config, rules, routes):
    present = {'route': False, 'local': False, 'gateway': False}
    problems = []
    for kind, priority in (('local', config['local_priority']), ('gateway', config['gateway_priority'])):
        selected = [r for r in rules if r.get('priority') == priority]
        if len(selected) > 1 or (selected and not rule_matches(selected[0], config, kind)):
            problems.append('Rule priority ' + str(priority) + ' is occupied by an unrecognized rule.')
        elif selected:
            present[kind] = True
    for rule in rules:
        if type(rule.get('priority')) is not int:
            problems.append('Unexpected rule priority encoding; refusing ambiguous ownership.')
        if 'table' in rule and table_id(rule['table']) is None:
            problems.append('Non-numeric unknown table name despite ip -N; refusing ambiguous ownership.')
        if table_id(rule.get('table')) == config['table'] and not rule_matches(rule, config, 'gateway'):
            problems.append('Another rule references the requested routing table.')
    if len(routes) > 1 or (routes and not route_matches(routes[0], config)):
        problems.append('Routing table contains unrecognized or additional routes.')
    elif routes:
        present['route'] = True
    return present, problems


def validate_gateway(config, interfaces):
    selected = [i for i in interfaces if i.get('ifname') == config['device']]
    if len(selected) != 1 or 'UP' not in selected[0].get('flags', []):
        raise PolicyError('Gateway interface must exist and be administratively UP.')
    addresses = selected[0].get('addr_info', [])
    gateway = ipaddress.IPv4Address(config['gateway'])
    locals_ = {a.get('local') for a in addresses if a.get('family') == 'inet'}
    if str(gateway) in locals_:
        raise PolicyError('Gateway cannot be a local interface address.')
    for address in addresses:
        if address.get('family') != 'inet' or address.get('valid_life_time') == 0:
            continue
        try:
            network = ipaddress.ip_interface(f"{address['local']}/{address['prefixlen']}").network
        except (KeyError, ValueError):
            continue
        if gateway in network and (network.prefixlen >= 31 or gateway not in (network.network_address, network.broadcast_address)):
            return
    raise PolicyError('Gateway must belong to a current IPv4 prefix on the selected interface; onlink is not a reachability bypass.')


class IPRoute:
    def __init__(self):
        self.binary = next((p for p in ('/usr/sbin/ip', '/sbin/ip', '/usr/bin/ip', '/bin/ip') if os.path.isfile(p)), None)
        if self.binary is None:
            raise PolicyError('iproute2 is required.')

    def run(self, args, missing_table=False):
        try:
            result = subprocess.run([self.binary, *args], text=True, capture_output=True,
                                    timeout=15, check=False, env={**os.environ, 'LC_ALL': 'C'})
        except subprocess.TimeoutExpired:
            raise PolicyError('iproute2 timed out; inspect the live policy before retrying.') from None
        if result.returncode:
            if missing_table and result.returncode == 2 and 'FIB table does not exist' in result.stderr:
                return '[]'
            raise PolicyError('iproute2 failed: ' + result.stderr.strip())
        return result.stdout

    def data(self, args, missing_table=False):
        try:
            value = json.loads(self.run(['-N', '-j', '-4', *args], missing_table))
        except ValueError:
            raise PolicyError('iproute2 returned invalid JSON.') from None
        if not isinstance(value, list) or any(not isinstance(item, dict) for item in value):
            raise PolicyError('iproute2 returned an unexpected JSON structure.')
        return value

    def snapshot(self, config):
        return (self.data(['rule', 'show']),
                self.data(['route', 'show', 'table', str(config['table'])], missing_table=True))

    def addresses(self, config):
        return self.data(['address', 'show', 'dev', config['device']])

    def change(self, action, kind, config):
        if kind == 'route':
            args = ['route', action, 'table', str(config['table']), 'default', 'via',
                    config['gateway'], 'dev', config['device'], 'onlink']
        else:
            priority = config['local_priority' if kind == 'local' else 'gateway_priority']
            table = 'main' if kind == 'local' else str(config['table'])
            args = ['rule', action, 'pref', str(priority), 'from', config['proxy_ip'] + '/32',
                    'ipproto', 'tcp', 'sport', str(config['port']), 'lookup', table]
            if kind == 'local':
                args += ['suppress_prefixlength', '0']
        self.run(['-4', *args])


class Policy:
    def __init__(self, config, ip):
        self.config, self.ip = config, ip

    def inspect(self):
        return inspect_snapshot(self.config, *self.ip.snapshot(self.config))

    def checked(self):
        present, problems = self.inspect()
        if problems:
            raise PolicyError(' '.join(problems))
        return present

    def apply(self, persist=lambda: None):
        original = self.checked()
        validate_gateway(self.config, self.ip.addresses(self.config))
        attempted = []
        try:
            # Repair main-table protection before activating a missing route if a
            # gateway rule survived a partial external change.
            order = ('local', 'route', 'gateway') if original['gateway'] and not original['local'] else ('route', 'local', 'gateway')
            for kind in order:
                current = self.checked()
                if not current[kind]:
                    # Include timed-out commands: netlink may already have committed them.
                    attempted.append(kind)
                    self.ip.change('add', kind, self.config)
            if not all(self.checked().values()):
                raise PolicyError('Policy changed during apply; refusing to record success.')
            persist()
        except Exception as error:
            failures = []
            for kind in reversed(attempted):
                if original[kind]:
                    continue
                try:
                    if self.checked()[kind]:
                        self.ip.change('del', kind, self.config)
                except Exception as rollback_error:
                    failures.append(str(rollback_error))
                    # Do not remove the local/main protection while the gateway rule may remain.
                    break
            detail = (' Rollback incomplete: ' + ' '.join(failures)) if failures else ' Newly added policy objects were rolled back.'
            raise PolicyError(str(error) + detail) from error
        return original

    def remove(self):
        self.checked()  # Refuse unknown table/priority contents before any deletion.
        for kind in ('gateway', 'local', 'route'):
            if self.checked()[kind]:
                self.ip.change('del', kind, self.config)
        if any(self.checked().values()):
            raise PolicyError('Policy changed during removal; retained state for investigation.')


def choose_config(configured, applied, action):
    if action == 'apply' and applied is not None and configured != applied:
        raise PolicyError('Configuration differs from applied state. Remove the old policy before applying the new configuration.')
    return applied if applied is not None else configured


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('apply', 'remove', 'status'))
    args = parser.parse_args(argv)
    if not hasattr(os, 'geteuid') or os.geteuid() != 0:
        raise PolicyError('Run as root on the Linux host.')
    import fcntl
    check_directories()
    lockfd = os.open('/run/lock/mailhub-return-route.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(lockfd, 'r+') as lock:
        info = os.fstat(lock.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1:
            raise PolicyError('Return-route lock must be a root-owned 0600 regular file with one link.')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise PolicyError('Another Mailhub return-route operation is running.') from None
        configured = read_secure(CONFIG)
        applied = read_secure(STATE, optional=True)
        effective = choose_config(configured, applied, args.action)
        policy = Policy(effective, IPRoute())
        if args.action == 'status':
            present, problems = policy.inspect()
            drift = applied is not None and configured != applied
            if drift:
                problems.append('Configuration differs from the saved applied policy.')
            state = 'conflict' if problems else 'applied' if all(present.values()) else 'absent' if not any(present.values()) else 'partial'
            print(json.dumps({'state': state, 'present': present, 'recorded': applied is not None,
                              'configuration_matches_state': not drift, 'problems': problems}, indent=2))
            return 0 if state == 'applied' else 3
        if args.action == 'apply':
            policy.apply(lambda: write_state(configured))
            print('Return policy applied or already identical; main routing table was not changed.')
        else:
            policy.remove()
            if applied is not None:
                STATE.unlink()
            print('Matching return policy removed or already absent; unrelated routes/rules were not changed.')
        return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (PolicyError, OSError) as error:
        print('Return-route: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
