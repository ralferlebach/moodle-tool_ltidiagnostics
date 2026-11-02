#!/usr/bin/env python3
# This file is part of Moodle - https://moodle.org/
#
# Moodle is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
#
# @package    tool_ltidiagnostics
# @copyright  2026 Ralf Erlebach
# @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
"""Real LTI 1.3 launch of a learner from a Moodle platform into a Moodle tool (two-site stack).

The complete OIDC flow runs over HTTP exactly as a browser would do it: platform login, mod/lti/launch.php, the
tool's initiate login, the platform's auth.php, the id_token POST to the tool's launch.php. A scripted client is used
instead of a browser because the two sites run on plain http with different hosts, where browsers drop the
cross-site cookies of the OIDC flow (SameSite); the requests and the validation on both sites are the real ones.

Usage: lti_launch.py --platform URL --username U --password P --cmid N
"""

import argparse
import html
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar


def forms(page):
    """Return the forms of a page as (action, method, fields)."""
    out = []
    for m in re.finditer(r'<form\b([^>]*)>(.*?)</form>', page, re.S | re.I):
        attrs, body = m.group(1), m.group(2)
        action = re.search(r'action="([^"]*)"', attrs)
        method = re.search(r'method="([^"]*)"', attrs, re.I)
        fields = {}
        for i in re.finditer(r'<input\b([^>]*)>', body, re.I):
            name = re.search(r'name="([^"]*)"', i.group(1))
            value = re.search(r'value="([^"]*)"', i.group(1))
            if name:
                fields[html.unescape(name.group(1))] = html.unescape(value.group(1)) if value else ''
        out.append((html.unescape(action.group(1)) if action else '', (method.group(1) if method else 'get').lower(),
                    fields))
    return out


class Client:
    """Cookie keeping HTTP client without proxies."""

    def __init__(self):
        self.jar = CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                                                  urllib.request.HTTPCookieProcessor(self.jar))

    def go(self, url, data=None, method=None):
        body = urllib.parse.urlencode(data).encode() if data is not None and method != 'get' else None
        if data is not None and method == 'get':
            url = url + ('&' if '?' in url else '?') + urllib.parse.urlencode(data)
        req = urllib.request.Request(url, data=body)
        try:
            with self.opener.open(req, timeout=60) as r:
                return r.geturl(), r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.geturl(), e.code, e.read().decode('utf-8', 'replace')


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--platform', required=True)
    p.add_argument('--username', required=True)
    p.add_argument('--password', required=True)
    p.add_argument('--cmid', required=True)
    a = p.parse_args()
    c = Client()
    url, status, page = c.go(a.platform + '/login/index.php')
    token = re.search(r'name="logintoken" value="([^"]+)"', page)
    url, status, page = c.go(a.platform + '/login/index.php', {'username': a.username, 'password': a.password,
                                                              'logintoken': token.group(1) if token else ''})
    if 'login/index.php' in url:
        sys.exit('platform login failed')
    url, status, page = c.go(a.platform + '/mod/lti/launch.php?id=' + a.cmid + '&triggerview=0')
    steps = [url]
    # Initiate login (platform -> tool), auth request (tool -> platform, a redirect), id_token POST (platform -> tool).
    for _ in range(4):
        f = forms(page)
        if not f or not f[0][0].startswith('http'):
            break
        action, method, fields = f[0]
        url, status, page = c.go(action, fields, method)
        steps.append('%s %s' % (status, url))
        if status >= 400:
            break
    print('\n'.join(steps))
    if status >= 400:
        main = re.search(r'<div role="main">(.*?)</div>', page, re.S)
        print(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', main.group(1) if main else page))[:1500])
        sys.exit('launch failed')
    print('OK', url)


if __name__ == '__main__':
    main()
