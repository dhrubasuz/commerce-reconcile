"""Package the minimal installable theme. Author / Creator: Dhruba Poudel."""
from pathlib import Path
import hashlib
import json
import shutil
import zipfile

theme = Path(__file__).resolve().parents[1]
licence = theme / 'LICENSE'
if not licence.exists():
    shutil.copyfile(theme.parent / 'LICENSE', licence)
target = theme / 'commerce-canvas-1.0.0.zip'
sources = sorted(theme.glob('*.php')) + [theme / 'style.css', theme / 'README.txt', licence]
sources += sorted(path for folder in ('assets', 'inc') for path in (theme / folder).rglob('*') if path.is_file())
screenshot = theme / 'screenshot.png'
if screenshot.exists():
    sources.append(screenshot)
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
    archive.comment = b'Commerce Canvas 1.0.0. Author / Creator / Last Modified By: Dhruba Poudel.'
    for source in sources:
        archive.write(source, 'commerce-canvas/' + source.relative_to(theme).as_posix())
with zipfile.ZipFile(target) as archive:
    assert archive.testzip() is None
    assert 'commerce-canvas/style.css' in archive.namelist()
    assert 'commerce-canvas/index.php' in archive.namelist()
print(json.dumps({'archive': str(target), 'files': len(sources), 'bytes': target.stat().st_size, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest()}))
