"""Static publication and PHP-fixture checks for Commerce Canvas.
Author / Creator / Last Modified By: Dhruba Poudel. GPL-2.0-or-later.
"""
import argparse
from html.parser import HTMLParser
import json
from pathlib import Path
import subprocess
from urllib.parse import unquote, urlsplit
import xml.etree.ElementTree as ET

THEME = Path(__file__).resolve().parents[1]
REPO = THEME.parent


class Page(HTMLParser):
    def __init__(self, text):
        super().__init__()
        self.ids = []
        self.refs = []
        self.h1 = 0
        self.authorship = {}
        self.images = []
        self.controls = []
        self.feed(text)

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if attrs.get('id'):
            self.ids.append(attrs['id'])
        if tag == 'h1':
            self.h1 += 1
        if tag == 'meta' and attrs.get('name') in ('author', 'creator', 'last-modified-by'):
            self.authorship[attrs['name']] = attrs.get('content')
        if tag == 'img':
            self.images.append(attrs)
        if attrs.get('aria-controls'):
            self.controls.append(attrs['aria-controls'])
        for key in ('href', 'src'):
            if attrs.get(key):
                self.refs.append(attrs[key])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', help='Optional PHP CLI for syntax and fixture alignment checks')
    args = parser.parse_args()
    paths = [THEME / 'preview/index.html', REPO / 'brand/index.html']
    pages = {path: Page(path.read_text(encoding='utf-8')) for path in paths}
    references = 0
    for path, page in pages.items():
        assert page.h1 == 1, (path, 'one H1')
        assert len(page.ids) == len(set(page.ids)), (path, 'unique IDs')
        assert page.authorship == {name: 'Dhruba Poudel' for name in ('author', 'creator', 'last-modified-by')}
        assert all(control in page.ids for control in page.controls)
        for image in page.images:
            assert 'alt' in image
            assert image['alt'] or image.get('src', '').endswith('canvas-mark.svg')
            assert image.get('width') and image.get('height')
        for ref in page.refs:
            url = urlsplit(ref)
            assert not url.scheme and not url.netloc, (path, 'no remote runtime or outbound link', ref)
            target = (path.parent / unquote(url.path)).resolve() if url.path else path
            assert target.is_relative_to(REPO.resolve()), (path, 'path boundary', ref)
            assert target.exists(), (path, 'missing local file', ref)
            if url.fragment:
                target_page = pages.get(target) or Page(target.read_text(encoding='utf-8'))
                assert unquote(url.fragment) in target_page.ids, (path, 'missing fragment', ref)
            references += 1
    svgs = list((THEME / 'assets/images').glob('*.svg'))
    assert len(svgs) == 9
    for path in svgs:
        root = ET.fromstring(path.read_text(encoding='utf-8'))
        assert root.find('{http://www.w3.org/2000/svg}title') is not None
        assert 'Dhruba Poudel' in ''.join(root.itertext())
        for node in root.iter():
            assert node.tag.rsplit('}', 1)[-1] not in ('script', 'foreignObject', 'image')
    preview = (THEME / 'preview/index.html').read_text(encoding='utf-8')
    assert preview.count('data-product-category=') == 6
    assert preview.count('class="cc-product-details"') == 6
    assert 'Fictional catalogue' in preview and 'No checkout' in preview
    css = (THEME / 'style.css').read_text(encoding='utf-8')
    assert '@import' not in css and 'url(http' not in css
    assert 'prefers-reduced-motion: reduce' in css
    assert 'Theme Name: Commerce Canvas' in css and 'Author: Dhruba Poudel' in css
    php_files = sorted(THEME.rglob('*.php'))
    fixture_status = 'NOT_RUN_NO_PHP_ARGUMENT'
    if args.php:
        for path in php_files:
            subprocess.run([args.php, '-l', str(path)], check=True, capture_output=True, text=True)
        code = "define('ABSPATH', __DIR__); echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);"
        fixtures = json.loads(subprocess.check_output([args.php, '-r', code, str(THEME / 'inc/sample-products.php')], text=True))
        assert len(fixtures) == 6
        for product in fixtures:
            for key in ('name', 'category', 'description', 'details', 'price'):
                # Escape differences do not change the visible fixture: apostrophes
                # are harmless text in PHP and encoded in the generated HTML.
                from html import unescape
                assert ' '.join(product[key].split()) in ' '.join(unescape(preview).split()), (product['name'], key)
            assert (THEME / 'assets/images' / product['image']).exists()
        fixture_status = 'SIX_PHP_FIXTURES_MATCH_STATIC_PREVIEW'
    report = {'author': 'Dhruba Poudel', 'creator': 'Dhruba Poudel', 'last_modified_by': 'Dhruba Poudel', 'static_pages': len(pages), 'local_references': references, 'original_svg_assets': len(svgs), 'php_files_linted': len(php_files) if args.php else 0, 'fixture_alignment': fixture_status, 'wordpress_runtime': 'SEPARATE_INTEGRATION_RECORD', 'browser_layout': 'SEPARATE_BROWSER_RECORD', 'status': 'STATIC_CHECKS_PASSED'}
    (THEME / 'quality.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report))


if __name__ == '__main__':
    main()
