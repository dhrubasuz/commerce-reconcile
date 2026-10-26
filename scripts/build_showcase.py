"""Author / Creator / Last Modified By: Dhruba Poudel."""
from pathlib import Path
import argparse, hashlib, json, shutil, zipfile, subprocess, sys

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
target = args.output.resolve()
target.mkdir(parents=True, exist_ok=True)
for path in (root / 'web').iterdir():
    if path.is_file(): shutil.copy2(path, target/path.name)
(target/'engine').mkdir(exist_ok=True)
for name in ('CanonicalJson.php', 'Reconciler.php'):
    shutil.copy2(root/'plugin/commerce-reconcile/includes'/name, target/'engine'/name)
shutil.copy2(root/'fixtures/scenario.json', target/'scenario.json')
shutil.copytree(root/'theme/preview', target/'theme/preview', dirs_exist_ok=True)
shutil.copytree(root/'theme/assets', target/'theme/assets', dirs_exist_ok=True)
shutil.copy2(root/'theme/style.css', target/'theme/style.css')
shutil.copytree(root/'brand', target/'brand', dirs_exist_ok=True)
downloads = target/'downloads'
downloads.mkdir(exist_ok=True)

def pack(folder, destination, prefix, exclusions=()):
    with zipfile.ZipFile(destination, 'w', zipfile.ZIP_DEFLATED) as z:
        z.comment = b'Author / Creator: Dhruba Poudel. Original GPL-2.0-or-later showcase.'
        for p in sorted(folder.rglob('*')):
            if p.is_file() and p.suffix != '.zip' and not any(part in (*exclusions, 'node_modules', '.git', '__pycache__') for part in p.relative_to(folder).parts) and p.name not in ('package.json','pnpm-lock.yaml','verification-2026-10-27.json','.gitignore'):
                z.write(p, (Path(prefix)/p.relative_to(folder)).as_posix())
    with zipfile.ZipFile(destination) as z: assert z.testzip() is None

pack(root/'plugin/commerce-reconcile', downloads/'commerce-reconcile-plugin.zip', 'commerce-reconcile')
subprocess.run([sys.executable,str(root/'theme/tools/package_theme.py')],check=True,capture_output=True,text=True)
shutil.copy2(root/'theme/commerce-canvas-1.0.0.zip',downloads/'commerce-canvas-theme.zip')
manifest = {'author':'Dhruba Poudel','creator':'Dhruba Poudel','last_modified_by':'Dhruba Poudel','version':'1.0.0',
            'scope':'Original WordPress plugin, custom theme and fictional-data PHP core demo.', 'downloads':[]}
for path in sorted(downloads.glob('*.zip')):
    manifest['downloads'].append({'file':path.name,'bytes':path.stat().st_size,'sha256':hashlib.sha256(path.read_bytes()).hexdigest()})
(downloads/'release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
print(json.dumps({'output':str(target),'files':sum(p.is_file() for p in target.rglob('*')),'downloads':manifest['downloads']}))
