"""Allowlisted Pi release. Never includes .env, runtime data, captures or cloud secrets."""
from pathlib import Path
import hashlib
import io
import shutil
import tarfile

ROOT=Path(__file__).resolve().parents[1]
DEST=ROOT/'portal/public/downloads'
DEST.mkdir(parents=True,exist_ok=True)
FILES=['bootstrap.php','.env.example','README.md','bin/solportal','scripts/install-raspberry-pi.sh']
DIRS=['src','config','database','public','resources','systemd','apache','tests']
files=[ROOT/f for f in FILES]
for folder in DIRS:
    files.extend(p for p in (ROOT/folder).rglob('*') if p.is_file() and not p.is_symlink())
archive=DEST/'solportalen-pi.tar.gz'
with tarfile.open(archive,'w:gz',format=tarfile.USTAR_FORMAT) as tar:
    for path in sorted(files):
        rel=path.relative_to(ROOT).as_posix()
        if path.name.startswith('.') and rel!='.env.example':continue
        if path.suffix.lower() in ['.pcap','.pcapng','.log','.bak','.pem','.key']:raise ValueError('Private file in release allowlist: '+rel)
        data=path.read_bytes()
        if path.suffix.lower() in ['.php','.sh','.sql','.js','.css','.md','.json','.conf','.service','.timer'] or rel in ['bin/solportal','.env.example']:
            data=data.replace(b'\r\n',b'\n')
        info=tarfile.TarInfo('solportalen/'+rel);info.size=len(data);info.mode=0o755 if rel=='bin/solportal' or path.suffix=='.sh' else 0o644
        tar.addfile(info,io.BytesIO(data))
digest=hashlib.sha256(archive.read_bytes()).hexdigest()
(DEST/'SHA256SUMS').write_text(digest+'  solportalen-pi.tar.gz\n',encoding='ascii')
guide=ROOT/'output/pdf/solportalen-installationsguide.pdf'
if not guide.is_file():raise FileNotFoundError('Build and visually review the PDF guide first')
shutil.copy2(guide,DEST/guide.name)
print(f'{len(files)} allowlisted files, {archive.stat().st_size} bytes, SHA256 {digest}')
