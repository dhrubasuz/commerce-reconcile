"""Read-only public-package checks. Author / Creator / Last Modified By: Dhruba Poudel."""
from __future__ import annotations

import argparse
from collections import Counter
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path, PurePosixPath
import re
import stat
import sys
from urllib.parse import unquote, urlsplit
import xml.etree.ElementTree as ET
import zipfile


AUTHOR = 'Dhruba Poudel'
EXPECTED_PAGES = {'index.html', 'theme/preview/index.html', 'brand/index.html'}
EXPECTED_DOWNLOADS = {
    'commerce-reconcile-plugin.zip': 'commerce-reconcile',
    'commerce-canvas-theme.zip': 'commerce-canvas',
}
FORBIDDEN_PARTS = {
    'node_modules', 'vendor', '.git', '.svn', '.hg', '__pycache__', '__tests__',
    'test', 'tests', 'coverage', '.nyc_output', 'runtime', 'wordpress', 'wp-content',
    'wp-includes', 'wp-admin', 'logs', 'temp', 'tmp', '.cache', '.idea', '.vscode',
}
FORBIDDEN_NAMES = {
    'wp-config.php', 'auth.json', 'credentials.json', 'secrets.json',
    'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock',
    'composer.json', 'composer.lock', 'phpunit.xml', 'phpunit.xml.dist',
    '.npmrc', '.pypirc', '.git-credentials', '.htpasswd', 'id_rsa', 'id_ed25519',
    'desktop.ini', 'thumbs.db', '.ds_store',
}
FORBIDDEN_SUFFIXES = {
    '.sqlite', '.sqlite3', '.db', '.sql', '.dump', '.bak', '.log', '.exe', '.dll',
    '.wasm', '.phar', '.pdb', '.pem', '.key', '.p12', '.pfx', '.map', '.pyc',
}
TEXT_SUFFIXES = {'.html', '.css', '.js', '.php', '.json', '.svg', '.md', '.txt'}
MAX_ZIP_BYTES = 2 * 1024 * 1024
MAX_ZIP_ENTRIES = 200
MAX_EXPANDED_BYTES = 10 * 1024 * 1024
MAX_ENTRY_BYTES = 2 * 1024 * 1024
CSS_URL = re.compile(r'url\(\s*[\'"]?([^\)\'"\s]+)[\'"]?\s*\)', re.I)
SECRET_PATTERNS = (
    ('private key', re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----')),
    ('AWS access key', re.compile(r'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b')),
    ('GitHub token', re.compile(r'\b(?:ghp_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{50,})\b')),
    ('provider API key', re.compile(r'\bsk-(?:proj-|live_|test_)[A-Za-z0-9_-]{20,}\b')),
)
OVERCLAIMS = (
    re.compile(r'\b100\s*%\s*(?:secure|accurate|reliable)\b', re.I),
    re.compile(r'\b(?:fully secure|production[- ]ready|enterprise[- ]grade|PCI[- ]certified)\b', re.I),
    re.compile(r'\bWordPress\.org (?:approved|certified)\b', re.I),
    re.compile(r'\b(?:guaranteed results|guaranteed savings|10\s*/\s*10)\b', re.I),
)


class Page(HTMLParser):
    def __init__(self, path: Path, source: str) -> None:
        super().__init__(convert_charrefs=True)
        self.path = path
        self.h1 = 0
        self.ids: list[str] = []
        self.meta: dict[str, list[str]] = {}
        self.refs: list[str] = []
        self.errors: list[str] = []
        self.images = 0
        self.decorative_images = 0
        self.text: list[str] = []
        self.hidden_text_depth = 0
        self.feed(source)
        self.close()

    def handle_starttag(self, tag: str, attributes: list[tuple[str, str | None]]) -> None:
        names = [name for name, _ in attributes]
        for name, count in Counter(names).items():
            if count > 1:
                self.errors.append(f'duplicate attribute {name!r} on {tag}, line {self.getpos()[0]}')
        attrs = dict(attributes)
        if tag == 'h1':
            self.h1 += 1
        if attrs.get('id') is not None:
            self.ids.append(str(attrs['id']))
        if tag == 'meta' and attrs.get('name'):
            self.meta.setdefault(str(attrs['name']).lower(), []).append(str(attrs.get('content', '')))
        if tag == 'base':
            self.errors.append('base URL overrides are unsupported in this self-contained package')
        if tag == 'img':
            self.images += 1
            if 'alt' not in attrs:
                self.errors.append(f'image missing an explicit alt attribute, line {self.getpos()[0]}')
            elif attrs['alt'] == '':
                self.decorative_images += 1
        for attr in ('href', 'src', 'poster'):
            if attrs.get(attr):
                self.refs.append(str(attrs[attr]))
        if attrs.get('srcset'):
            self.refs.extend(part.strip().split()[0] for part in str(attrs['srcset']).split(',') if part.strip())
        if attrs.get('style'):
            self.refs.extend(CSS_URL.findall(str(attrs['style'])))
        if tag in {'script', 'style'}:
            self.hidden_text_depth += 1

    def handle_startendtag(self, tag: str, attributes: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attributes)
        if tag in {'script', 'style'}:
            self.hidden_text_depth = max(0, self.hidden_text_depth - 1)

    def handle_endtag(self, tag: str) -> None:
        if tag in {'script', 'style'}:
            self.hidden_text_depth = max(0, self.hidden_text_depth - 1)

    def handle_data(self, data: str) -> None:
        if not self.hidden_text_depth:
            self.text.append(data)


class Checker:
    def __init__(self, root: Path) -> None:
        self.root = root.resolve(strict=True)
        self.errors: list[str] = []
        self.error_count = 0
        self.pages: dict[Path, Page] = {}
        self.files: list[Path] = []
        self.local_refs = 0
        self.downloads: list[dict] = []

    def fail(self, location: str | Path, message: str) -> None:
        if isinstance(location, Path):
            try:
                location = location.relative_to(self.root).as_posix()
            except ValueError:
                location = str(location)
        self.error_count += 1
        if len(self.errors) < 40:
            self.errors.append(f'{location}: {message}')

    @staticmethod
    def unsafe_name(name: str) -> str | None:
        path = PurePosixPath(name)
        lowered = [part.lower() for part in path.parts]
        if not name or '\x00' in name or '\\' in name or path.is_absolute() or any(part in {'..', ''} for part in path.parts) or re.match(r'^[A-Za-z]:', name):
            return 'unsafe or non-portable path'
        if any(part in FORBIDDEN_PARTS for part in lowered):
            return 'development, dependency, runtime or test directory'
        basename = path.name.lower()
        if basename in FORBIDDEN_NAMES or basename == '.env' or basename.startswith('.env.'):
            return 'credential/configuration/development file'
        if path.suffix.lower() in FORBIDDEN_SUFFIXES:
            return 'database, executable, private key, log or development output'
        if re.search(r'(?:^test[_-].*\.(?:php|js|mjs|py)|[._-](?:test|spec)\.(?:php|js|mjs|py))$', basename):
            return 'test implementation file'
        return None

    def scan_secrets(self, label: str | Path, source: str) -> None:
        for kind, pattern in SECRET_PATTERNS:
            if pattern.search(source):
                self.fail(label, f'possible {kind}; inspect and remove before publication')

    def collect(self) -> None:
        for path in sorted(self.root.rglob('*')):
            relative = path.relative_to(self.root).as_posix()
            if path.is_symlink():
                self.fail(path, 'symbolic links are not allowed in a public package')
                continue
            if not path.is_file():
                continue
            if not path.resolve().is_relative_to(self.root):
                self.fail(path, 'resolved file escapes the public directory')
                continue
            self.files.append(path)
            unsafe = self.unsafe_name(relative)
            if unsafe:
                self.fail(path, unsafe)
            if path.suffix.lower() in TEXT_SUFFIXES:
                try:
                    source = path.read_text(encoding='utf-8-sig')
                except UnicodeError:
                    self.fail(path, 'text asset is not valid UTF-8')
                    continue
                self.scan_secrets(path, source)
                if path.suffix.lower() == '.html':
                    self.pages[path.resolve()] = Page(path, source)

    def reference(self, owner: Path, raw: str) -> None:
        raw = raw.strip()
        url = urlsplit(raw)
        if url.scheme or url.netloc:
            if url.scheme.lower() not in {'', 'https', 'http', 'mailto', 'tel', 'data'}:
                self.fail(owner, f'unsafe URL scheme in {raw!r}')
            if url.scheme.lower() == 'data' and not raw.lower().startswith('data:image/'):
                self.fail(owner, 'only image data URLs may be embedded')
            return
        decoded = unquote(url.path)
        if '\\' in decoded or '\x00' in decoded:
            self.fail(owner, f'non-portable or null-containing local URL {raw!r}')
            return
        # A leading slash is relative to this deployment root, never this machine's filesystem.
        target = (self.root / decoded.lstrip('/')) if decoded.startswith('/') else (owner.parent / decoded)
        if not decoded:
            target = owner
        target = target.resolve()
        if not target.is_relative_to(self.root):
            self.fail(owner, f'local URL escapes the deployment root: {raw!r}')
            return
        if target.is_dir():
            target = target / 'index.html'
        if not target.is_file():
            self.fail(owner, f'missing local target {raw!r}')
            return
        self.local_refs += 1
        fragment = unquote(url.fragment)
        if fragment and target.suffix.lower() == '.html':
            page = self.pages.get(target.resolve())
            if page is None or fragment not in page.ids:
                self.fail(owner, f'missing HTML fragment in {raw!r}')
        elif fragment and target.suffix.lower() == '.svg':
            try:
                ids = {element.attrib['id'] for element in ET.parse(target).iter() if 'id' in element.attrib}
                if fragment not in ids:
                    self.fail(owner, f'missing SVG fragment in {raw!r}')
            except ET.ParseError:
                self.fail(target, 'invalid SVG XML')

    def check_pages(self) -> None:
        actual = {page.relative_to(self.root).as_posix() for page in self.pages}
        if actual != EXPECTED_PAGES:
            self.fail('.', f'expected exactly the three showcase pages; missing={sorted(EXPECTED_PAGES-actual)}, extra={sorted(actual-EXPECTED_PAGES)}')
        for path, page in self.pages.items():
            if page.h1 != 1:
                self.fail(path, f'expected one H1; found {page.h1}')
            for name in ('author', 'creator', 'last-modified-by'):
                if page.meta.get(name) != [AUTHOR]:
                    self.fail(path, f'{name} metadata must be exactly {AUTHOR!r}')
            for element_id, count in Counter(page.ids).items():
                if not element_id or count > 1:
                    self.fail(path, f'empty or duplicate ID {element_id!r}')
            for error in page.errors:
                self.fail(path, error)
            text = re.sub(r'\s+', ' ', ' '.join(page.text)).strip()
            for pattern in OVERCLAIMS:
                if pattern.search(text):
                    self.fail(path, f'unsupported public-claim phrase requires manual review: {pattern.search(text).group(0)!r}')
            for raw in page.refs:
                self.reference(path, raw)
        for path in self.files:
            if path.suffix.lower() == '.css':
                for raw in CSS_URL.findall(path.read_text(encoding='utf-8-sig')):
                    self.reference(path, raw)
            elif path.suffix.lower() == '.svg':
                try:
                    tree = ET.parse(path)
                    for element in tree.iter():
                        for name, value in element.attrib.items():
                            if name.rsplit('}', 1)[-1] in {'href', 'src'}:
                                self.reference(path, value)
                            for raw in CSS_URL.findall(value):
                                self.reference(path, raw)
                except ET.ParseError:
                    self.fail(path, 'invalid SVG XML')

    def check_archive(self, path: Path, prefix: str) -> None:
        if path.stat().st_size > MAX_ZIP_BYTES:
            self.fail(path, 'installable ZIP exceeds 2 MiB; unexpected bundled dependencies or outputs may be present')
        try:
            with zipfile.ZipFile(path) as archive:
                entries = archive.infolist()
                expanded = sum(entry.file_size for entry in entries)
                if len(entries) > MAX_ZIP_ENTRIES or expanded > MAX_EXPANDED_BYTES:
                    self.fail(path, f'archive exceeds bounded package size: {len(entries)} entries, {expanded} expanded bytes')
                if AUTHOR.encode() not in archive.comment:
                    self.fail(path, 'ZIP comment does not identify Dhruba Poudel as author/creator')
                names: set[str] = set()
                inspect_contents = expanded <= MAX_EXPANDED_BYTES and len(entries) <= MAX_ZIP_ENTRIES
                for entry in entries:
                    name = entry.filename
                    unsafe = self.unsafe_name(name)
                    if unsafe:
                        self.fail(path, f'{name}: {unsafe}')
                    if not PurePosixPath(name).parts or PurePosixPath(name).parts[0] != prefix:
                        self.fail(path, f'{name}: file is outside the single installable root {prefix!r}')
                    if PurePosixPath(name).suffix.lower() in {'.zip', '.tar', '.gz', '.7z', '.rar'}:
                        self.fail(path, f'nested archive entry {name!r} is not permitted')
                    if name.lower() in names:
                        self.fail(path, f'duplicate or case-colliding archive entry {name!r}')
                    names.add(name.lower())
                    if stat.S_ISLNK(entry.external_attr >> 16):
                        self.fail(path, f'symbolic-link archive entry {name!r}')
                    if entry.flag_bits & 1:
                        self.fail(path, f'encrypted archive entry {name!r}')
                    if entry.file_size > MAX_ENTRY_BYTES:
                        self.fail(path, f'oversized archive entry {name!r}')
                        inspect_contents = False
                    if inspect_contents and not entry.is_dir() and PurePosixPath(name).suffix.lower() in TEXT_SUFFIXES:
                        try:
                            self.scan_secrets(f'{path.name}!{name}', archive.read(entry).decode('utf-8-sig'))
                        except UnicodeError:
                            self.fail(path, f'non-UTF-8 text archive entry {name!r}')
                required = [f'{prefix}/LICENSE']
                required += [f'{prefix}/commerce-reconcile.php', f'{prefix}/includes/Reconciler.php'] if prefix == 'commerce-reconcile' else [f'{prefix}/style.css', f'{prefix}/index.php', f'{prefix}/functions.php']
                for name in required:
                    if name.lower() not in names:
                        self.fail(path, f'missing installable component {name!r}')
                if inspect_contents:
                    bad = archive.testzip()
                    if bad:
                        self.fail(path, f'ZIP CRC validation failed: {bad}')
        except (zipfile.BadZipFile, RuntimeError, OSError) as error:
            self.fail(path, f'unreadable ZIP: {error}')

    def check_downloads(self) -> None:
        manifest_path = self.root / 'downloads/release-manifest.json'
        try:
            manifest = json.loads(manifest_path.read_text(encoding='utf-8-sig'))
        except (OSError, ValueError) as error:
            self.fail(manifest_path, f'unreadable release manifest: {error}')
            return
        for name in ('author', 'creator', 'last_modified_by'):
            if manifest.get(name) != AUTHOR:
                self.fail(manifest_path, f'{name} must be {AUTHOR!r}')
        records = manifest.get('downloads')
        if not isinstance(records, list) or not all(isinstance(record, dict) for record in records):
            self.fail(manifest_path, 'downloads must be a list of records')
            return
        if not all(isinstance(record.get('file'), str) for record in records):
            self.fail(manifest_path, 'every download file must be a filename string')
            return
        if Counter(record.get('file') for record in records) != Counter(EXPECTED_DOWNLOADS.keys()):
            self.fail(manifest_path, 'manifest must list each expected plugin/theme ZIP exactly once')
        actual_zips = {path.relative_to(self.root).as_posix() for path in self.root.rglob('*.zip')}
        expected_zips = {'downloads/' + name for name in EXPECTED_DOWNLOADS}
        if actual_zips != expected_zips:
            self.fail('downloads', f'unexpected or missing ZIP files: {sorted(actual_zips ^ expected_zips)}')
        for record in records:
            name = record.get('file')
            if name not in EXPECTED_DOWNLOADS:
                continue
            path = self.root / 'downloads' / name
            if not path.is_file():
                self.fail(path, 'manifest download is missing')
                continue
            data = path.read_bytes()
            digest = hashlib.sha256(data).hexdigest()
            if type(record.get('bytes')) is not int or record['bytes'] != len(data):
                self.fail(path, 'byte length does not match release manifest')
            if record.get('sha256') != digest:
                self.fail(path, 'SHA-256 does not match release manifest')
            self.check_archive(path, EXPECTED_DOWNLOADS[name])
            self.downloads.append({'file': name, 'bytes': len(data), 'sha256': digest})

    def run(self) -> dict:
        self.collect()
        self.check_pages()
        self.check_downloads()
        for required in ('api.php', 'scenario.json', 'engine/CanonicalJson.php', 'engine/Reconciler.php'):
            if not (self.root / required).is_file():
                self.fail(required, 'required PHP demonstration runtime file is missing')
        return {
            'author': AUTHOR, 'creator': AUTHOR, 'last_modified_by': AUTHOR,
            'status': 'PASS' if not self.errors else 'FAIL',
            'directory': str(self.root), 'files': len(self.files), 'html_pages': len(self.pages),
            'local_references': self.local_refs,
            'images': sum(page.images for page in self.pages.values()),
            'explicit_decorative_images': sum(page.decorative_images for page in self.pages.values()),
            'downloads': self.downloads, 'errors': self.errors,
            'error_count': self.error_count, 'errors_omitted': self.error_count - len(self.errors),
            'scope': 'Static package, links, authorship, bounded ZIPs, manifest hashes and selected secret/overclaim patterns. This does not certify security, accessibility, every factual claim or deployed runtime behaviour.',
        }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, required=True, help='Built public showcase directory')
    args = parser.parse_args()
    try:
        result = Checker(args.directory).run()
    except (OSError, ValueError) as error:
        result = {'author': AUTHOR, 'status': 'FAIL', 'errors': [str(error)]}
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0 if result['status'] == 'PASS' else 1


if __name__ == '__main__':
    sys.exit(main())
