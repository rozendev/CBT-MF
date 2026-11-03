#!/usr/bin/env python3
"""Native Android builder; only the Python standard library is required."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import secrets
import shutil
import stat
import struct
import subprocess
import sys
import tarfile
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'cbt-kiosk-app'
SDK_TOOLS_URL = 'https://dl.google.com/android/repository/commandlinetools-linux-15859902_latest.zip'
SDK_TOOLS_SHA256 = '4e4c464f145a7512b57d088ac6c278c03c9eea610886b35a5e0804e74eedf583'


class BuildError(Exception):
    pass


def run(args, env=None, **kwargs):
    return subprocess.run([str(value) for value in args], env=env, check=True, **kwargs)


def project_config(source):
    build = (source / 'app/build.gradle.kts').read_text()
    wrapper = (source / 'gradle/wrapper/gradle-wrapper.properties').read_text()
    sdk = re.search(r'\bcompileSdk\s*=\s*(\d+)', build)
    tools = re.search(r'\bbuildToolsVersion\s*=\s*"([\d.]+)"', build)
    distribution = re.search(r'^distributionUrl=(.+)$', wrapper, re.M)
    url = distribution.group(1).strip().replace('\\:', ':') if distribution else ''
    version = re.search(r'/gradle-([\d.]+)-(?:bin|all)\.zip$', url)
    if not sdk or not version or urllib.parse.urlsplit(url).scheme != 'https':
        raise BuildError('Versi SDK/Gradle proyek tidak dapat dibaca.')
    return {'sdk': int(sdk.group(1)), 'build_tools': tools.group(1) if tools else '35.0.0',
            'gradle_version': version.group(1), 'gradle_url': url}


def open_url(url):
    if urllib.parse.urlsplit(url).scheme != 'https':
        raise BuildError('Unduhan tool memerlukan HTTPS.')
    response = urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'CBT-MF-builder/1'}), timeout=60)
    if urllib.parse.urlsplit(response.geturl()).scheme != 'https':
        response.close()
        raise BuildError('Redirect unduhan harus tetap HTTPS.')
    return response


def file_hash(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def download(url, target, checksum):
    if not re.fullmatch(r'[a-fA-F0-9]{64}', checksum):
        raise BuildError('Checksum SHA-256 tool tidak valid.')
    if target.is_file() and file_hash(target) == checksum.lower():
        return
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=target.parent, delete=False) as output:
            temporary = Path(output.name)
            with open_url(url) as response:
                shutil.copyfileobj(response, output)
        if file_hash(temporary) != checksum.lower():
            raise BuildError('Checksum unduhan berbeda; tool tidak dipasang.')
        temporary.replace(target)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def unpack_zip(archive, target):
    target.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(archive) as bundle:
        for member in bundle.infolist():
            destination = (target / member.filename).resolve()
            if not destination.is_relative_to(target.resolve()) or stat.S_ISLNK(member.external_attr >> 16):
                raise BuildError('Path archive tool tidak valid.')
            bundle.extract(member, target)
            if destination.is_file() and (member.external_attr >> 16) & 0o111:
                destination.chmod(0o755)


def find_jdk(state):
    candidates = [Path(os.environ['JAVA_HOME'])] if os.environ.get('JAVA_HOME') else []
    candidates.append(state / 'jdk')
    compiler = shutil.which('javac')
    if compiler:
        candidates.append(Path(compiler).resolve().parents[1])
    for candidate in candidates:
        if not all((candidate / 'bin' / tool).is_file() for tool in ['java', 'javac', 'keytool']):
            continue
        result = subprocess.run([str(candidate / 'bin/java'), '-version'], capture_output=True, text=True, timeout=20)
        if result.returncode == 0 and re.search(r'(?:version\s+"|openjdk\s+)21[.\s"]', result.stdout + result.stderr):
            return candidate
    return None


def sdk_missing(sdk, config):
    required = {'platform Android ' + str(config['sdk']): sdk / ('platforms/android-' + str(config['sdk'])) / 'android.jar'}
    for tool in ['aapt', 'apksigner', 'zipalign']:
        required[tool + ' ' + config['build_tools']] = sdk / 'build-tools' / config['build_tools'] / tool
    return [name for name, path in required.items()
            if not path.is_file() or (path.name != 'android.jar' and not os.access(path, os.X_OK))]


def sdk_candidates(source, state):
    values = [os.environ.get('ANDROID_HOME'), os.environ.get('ANDROID_SDK_ROOT')]
    local = source / 'local.properties'
    if local.is_file():
        for line in local.read_text().splitlines():
            if line.startswith('sdk.dir='):
                values.append(line.split('=', 1)[1].replace('\\:', ':').replace('\\ ', ' ').replace('\\\\', '\\'))
    values += [str(Path.home() / 'Android/Sdk'), '/opt/android-sdk', str(state / 'sdk')]
    return [Path(value).expanduser().resolve() for value in values if value]


def find_sdk(source, state, config):
    candidates = sdk_candidates(source, state)
    return next((sdk for sdk in candidates if not sdk_missing(sdk, config)),
                next((sdk for sdk in candidates if sdk.is_dir()), state / 'sdk'))


def find_gradle(state, config):
    version = config['gradle_version']
    candidates = [state / 'gradle' / ('gradle-' + version) / 'bin/gradle']
    home = Path(os.environ.get('GRADLE_USER_HOME', str(Path.home() / '.gradle')))
    candidates += list((home / 'wrapper/dists').glob('gradle-' + version + '-*/*/gradle-' + version + '/bin/gradle'))
    system = shutil.which('gradle')
    if system:
        binary = Path(system).resolve()
        if (binary.parents[1] / 'lib' / ('gradle-launcher-' + version + '.jar')).is_file():
            candidates.append(binary)
    return next((path for path in candidates if path.is_file() and os.access(path, os.X_OK)), None)


def doctor(source, state, config):
    jdk = find_jdk(state)
    sdk = find_sdk(source, state, config)
    gradle = find_gradle(state, config)
    missing = sdk_missing(sdk, config)
    print('Python: ' + sys.version.split()[0])
    print('JDK 21: ' + (str(jdk) if jdk else 'BELUM TERSEDIA'))
    print('Android SDK: ' + str(sdk))
    print('SDK/platform/build-tools: ' + (', '.join(missing) if missing else 'SIAP'))
    print('Gradle ' + config['gradle_version'] + ': ' + (str(gradle) if gradle else 'BELUM TERSEDIA'))
    if not jdk or missing or not gradle:
        print('Jalankan: bash scripts/cbt.sh android setup [--yes]')
        return 1
    return 0


def ensure_tools(source, state, config, accept):
    state.mkdir(parents=True, exist_ok=True, mode=0o700)
    jdk = find_jdk(state)
    if jdk is None:
        print('Memasang JDK 21 lokal...', flush=True)
        with open_url('https://api.adoptium.net/v3/assets/latest/21/hotspot?architecture=x64&image_type=jdk&os=linux&vendor=eclipse') as response:
            assets = json.load(response)
        package = assets[0]['binary']['package']
        archive = state / 'downloads/jdk21.tar.gz'
        download(package['link'], archive, package['checksum'])
        if not hasattr(tarfile, 'data_filter'):
            raise BuildError('Python perlu pembaruan patch yang menyediakan tarfile.data_filter.')
        with tempfile.TemporaryDirectory(dir=state) as temporary:
            with tarfile.open(archive) as bundle:
                bundle.extractall(temporary, filter='data')
            roots = [path for path in Path(temporary).iterdir() if (path / 'bin/javac').is_file()]
            if len(roots) != 1:
                raise BuildError('Isi archive JDK tidak dikenali.')
            if (state / 'jdk').exists():
                shutil.rmtree(state / 'jdk')
            shutil.move(str(roots[0]), state / 'jdk')
        jdk = find_jdk(state)
        if jdk is None:
            raise BuildError('JDK 21 hasil pemasangan tidak dapat dijalankan.')
    env = dict(os.environ, JAVA_HOME=str(jdk))
    env['PATH'] = str(jdk / 'bin') + os.pathsep + env.get('PATH', '')
    sdk = find_sdk(source, state, config)
    if sdk_missing(sdk, config):
        if not accept and not sys.stdin.isatty():
            raise BuildError('Pemasangan SDK memerlukan penerimaan lisensi: jalankan ulang dengan --yes atau terminal interaktif.')
        if not sdk.is_dir() or not os.access(sdk, os.W_OK):
            sdk = state / 'sdk'
        sdk.mkdir(parents=True, exist_ok=True)
        manager = sdk / 'cmdline-tools/latest/bin/sdkmanager'
        if not manager.is_file():
            archive = state / 'downloads/android-commandline-tools.zip'
            print('Memasang Android command-line tools...', flush=True)
            download(SDK_TOOLS_URL, archive, SDK_TOOLS_SHA256)
            with tempfile.TemporaryDirectory(dir=state) as temporary:
                unpack_zip(archive, Path(temporary))
                (sdk / 'cmdline-tools').mkdir(exist_ok=True)
                latest = sdk / 'cmdline-tools/latest'
                if latest.exists():
                    shutil.rmtree(latest)
                shutil.move(str(Path(temporary) / 'cmdline-tools'), latest)
            manager.chmod(0o755)
        print('Lisensi Android SDK: https://developer.android.com/studio/terms', flush=True)
        options = {'input': 'y\n' * 200, 'text': True} if accept else {}
        run([manager, '--sdk_root=' + str(sdk), '--licenses'], env=env, **options)
        run([manager, '--sdk_root=' + str(sdk), '--install', 'platform-tools',
             'platforms;android-' + str(config['sdk']), 'build-tools;' + config['build_tools']], env=env, **options)
        if sdk_missing(sdk, config):
            raise BuildError('SDK belum lengkap setelah pemasangan.')
    gradle = find_gradle(state, config)
    if gradle is None:
        print('Memasang Gradle ' + config['gradle_version'] + ' sesuai wrapper proyek...', flush=True)
        with open_url(config['gradle_url'] + '.sha256') as response:
            checksum = response.read().decode().split()[0]
        archive = state / 'downloads' / Path(urllib.parse.urlsplit(config['gradle_url']).path).name
        download(config['gradle_url'], archive, checksum)
        with tempfile.TemporaryDirectory(dir=state) as temporary:
            unpack_zip(archive, Path(temporary))
            target = state / 'gradle' / ('gradle-' + config['gradle_version'])
            target.parent.mkdir(exist_ok=True)
            if target.exists():
                shutil.rmtree(target)
            shutil.move(str(Path(temporary) / target.name), target)
        gradle = find_gradle(state, config)
        if gradle is None:
            raise BuildError('Gradle hasil pemasangan tidak dapat dijalankan.')
    env.update(ANDROID_HOME=str(sdk), ANDROID_SDK_ROOT=str(sdk))
    return env, sdk, gradle


def android_string(value):
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('@', '\\@').replace('?', '\\?') + '"'


def branding(source, resources, name, icon):
    if not name.strip() or len(name) > 80 or any(ord(char) < 32 or ord(char) == 127 for char in name):
        raise BuildError('Nama aplikasi harus 1–80 karakter tanpa karakter kontrol.')
    extension = None
    if icon is not None:
        if not icon.is_file() or icon.stat().st_size > 10 * 1024 * 1024:
            raise BuildError('Icon harus berupa berkas gambar maksimal 10 MiB.')
        with icon.open('rb') as stream:
            header = stream.read(32)
        if header.startswith(b'\x89PNG\r\n\x1a\n'):
            extension = 'png'
        elif header.startswith(b'\xff\xd8\xff'):
            extension = 'jpg'
        elif header[:4] == b'RIFF' and header[8:12] == b'WEBP':
            extension = 'webp'
        else:
            raise BuildError('Icon harus PNG, JPEG, atau WebP yang valid.')
    if resources.exists():
        shutil.rmtree(resources)
    (resources / 'values').mkdir(parents=True)
    original = ET.parse(source / 'app/src/main/res/values/strings.xml').getroot()
    root = ET.Element('resources')
    brand = re.compile('CBT-MF(?: Kiosk)?')
    for element in original:
        key = element.attrib.get('name')
        if key not in ['app_name', 'setup_title', 'home_launcher_title', 'home_launcher_message']:
            continue
        value = name if key in ['app_name', 'setup_title'] else brand.sub(
            lambda _match: name, element.text or '')
        ET.SubElement(root, 'string', name=key, formatted='false').text = android_string(value)
    for alias, fallback in [('cbt_launcher_icon', 'ic_launcher'), ('cbt_launcher_round', 'ic_launcher_round'), ('cbt_setup_icon', 'ic_shield')]:
        ET.SubElement(root, 'item', type='drawable', name=alias).text = '@drawable/' + ('cbt_custom_icon' if icon else fallback)
    if icon is not None:
        (resources / 'drawable-nodpi').mkdir()
        shutil.copyfile(icon, resources / 'drawable-nodpi' / ('cbt_custom_icon.' + extension))
    ET.ElementTree(root).write(resources / 'values/branding.xml', encoding='utf-8', xml_declaration=True)


def copy_project(source, destination, sdk):
    destination.mkdir(parents=True, exist_ok=True)
    for name in ['settings.gradle.kts', 'build.gradle.kts', 'gradle.properties', 'app/build.gradle.kts', 'app/proguard-rules.pro']:
        target = destination / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source / name, target)
    for name in ['app/src', 'gradle']:
        target = destination / name
        if target.exists():
            shutil.rmtree(target)
        shutil.copytree(source / name, target)
    escaped = str(sdk).replace('\\', '\\\\').replace(' ', '\\ ').replace(':', '\\:')
    (destination / 'local.properties').write_text('sdk.dir=' + escaped + '\n')


def signing_key(directory, env):
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    directory.chmod(0o700)
    key, secret = directory / 'release.p12', directory / 'password'
    if key.exists() != secret.exists():
        raise BuildError('Pasangan kunci/password tidak lengkap. Pulihkan backup; kunci tidak ditimpa.')
    if not key.exists():
        with tempfile.TemporaryDirectory(dir=directory) as temporary:
            temporary = Path(temporary)
            password = temporary / 'password'
            password.write_text(secrets.token_urlsafe(32) + '\n')
            password.chmod(0o600)
            temporary_key = temporary / 'release.p12'
            run(['keytool', '-genkeypair', '-noprompt', '-storetype', 'PKCS12', '-keystore', temporary_key,
                 '-alias', 'cbt-local', '-keyalg', 'RSA', '-keysize', '2048', '-validity', '10000',
                 '-dname', 'CN=CBT Local Builder', '-storepass:file', password, '-keypass:file', password], env=env)
            temporary_key.chmod(0o600)
            temporary_key.replace(key)
            password.replace(secret)
    key.chmod(0o600)
    secret.chmod(0o600)
    run(['keytool', '-list', '-keystore', key, '-storepass:file', secret, '-alias', 'cbt-local'],
        env=env, stdout=subprocess.DEVNULL)
    return key, secret


def sign_apk(unsigned, signed, tools, key, secret, env):
    aligned = signed.parent / 'aligned.apk'
    run([tools / 'zipalign', '-p', '-f', '4', unsigned, aligned], env=env)
    # PKCS12 uses the same password for this key and store. Omitting --key-pass
    # lets apksigner reuse it instead of consuming a second line from the file.
    run([tools / 'apksigner', 'sign', '--ks', key, '--ks-key-alias', 'cbt-local', '--ks-pass', 'file:' + str(secret),
         '--v4-signing-enabled', 'false', '--out', signed, aligned], env=env)
    run([tools / 'apksigner', 'verify', '--verbose', signed], env=env)


def image_dimensions(data):
    """Baca dimensi gambar dari header PNG/JPEG/WebP tanpa decoder penuh."""
    if data[:8] == b'\x89PNG\r\n\x1a\n':
        if len(data) < 29 or data[12:16] != b'IHDR':
            return None
        return struct.unpack('>II', data[16:24])
    if data[:4] == b'RIFF' and data[8:12] == b'WEBP':
        offset, total = 0, len(data) - 12
        while offset + 8 <= total:
            fourcc = data[12 + offset:16 + offset]
            length = struct.unpack_from('<I', data, 16 + offset)[0]
            payload = data[20 + offset:20 + offset + min(length, max(total - offset - 8, 0))]
            if fourcc == b'VP8X' and len(payload) >= 10:
                return int.from_bytes(payload[4:7], 'little') + 1, int.from_bytes(payload[7:10], 'little') + 1
            if fourcc == b'VP8 ' and len(payload) >= 10 and payload[3:6] == b'\x9d\x01\x2a':
                width, height = struct.unpack_from('<HH', payload, 6)
                return (width & 0x3FFF) + 1, (height & 0x3FFF) + 1
            if fourcc == b'VP8L' and len(payload) >= 5 and payload[0] == 0x2F:
                bits = int.from_bytes(payload[1:5], 'little')
                return (bits & 0x3FFF) + 1, ((bits >> 14) & 0x3FFF) + 1
            if length == 0:
                return None
            offset += 8 + length + (length & 1)
        return None
    if data[:3] == b'\xff\xd8\xff':
        offset = 2
        while offset + 1 < len(data):
            index = offset
            while index < len(data) and data[index] == 0xFF:
                index += 1
            if index + 1 >= len(data):
                return None
            marker = data[index]
            offset = index + 1
            if marker in (0x00, 0x01, 0xD8, 0xD9) or 0xD0 <= marker <= 0xD7:
                continue
            if offset + 2 > len(data):
                return None
            length = struct.unpack_from('>H', data, offset)[0]
            if length < 2 or offset + length > len(data):
                return None
            if 0xC0 <= marker <= 0xCF and marker not in (0xC4, 0xC8, 0xCC):
                if length < 7:
                    return None
                height, width = struct.unpack_from('>HH', data, offset + 3)
                return width, height
            offset += length
        return None
    return None


def decode_badging_value(value):
    result = []
    offset = 0
    while offset < len(value):
        char = value[offset]
        if char != '\\':
            result.append(char)
            offset += 1
            continue
        offset += 1
        if offset == len(value) or value[offset] not in ('\\', '"'):
            raise BuildError('Keluaran aapt memuat escape yang tidak dikenal.')
        result.append(value[offset])
        offset += 1
    return ''.join(result)


def verify_packaged_apk(metadata, members, name, icon=None):
    package = re.search(r"^package: name='([^']*)'", metadata, re.M)
    if not package or package.group(1) != 'id.sch.cbt.kiosk':
        raise BuildError('Package ID APK tidak sesuai dengan konfigurasi build.')
    labels = [decode_badging_value(line) for line in re.findall(r"^application-label:'(.*)'$", metadata, re.M)]
    if labels != [name]:
        raise BuildError('Label APK tidak sesuai nama aplikasi.')
    if 'application-debuggable' in metadata:
        raise BuildError('APK hasil build masih debuggable.')
    paths = set(re.findall(r"^application-icon-\d+:'(.*)'$", metadata, re.M))
    if not paths or any(path not in members or not members[path] for path in paths):
        raise BuildError('Icon manifest tidak seluruhnya terkemas dalam APK.')
    if icon is not None:
        wanted = image_dimensions(icon.read_bytes())
        for path in sorted(paths):
            packed = image_dimensions(members[path])
            if wanted is None or packed is None or packed != wanted:
                raise BuildError('Icon terkemas tidak cocok dengan berkas icon pilihan user.')


def build(source, state, config, args, env, sdk, gradle):
    saved_file = state / 'branding.json'
    saved = json.loads(saved_file.read_text()) if saved_file.is_file() else {}
    defaults = ET.parse(source / 'app/src/main/res/values/strings.xml').getroot()
    default_name = next(element.text for element in defaults if element.attrib.get('name') == 'app_name')
    name = args.name if args.name is not None else saved.get('name', default_name)
    icon_value = args.icon if args.icon is not None else saved.get('icon', '')
    if sys.stdin.isatty():
        if args.name is None:
            name = input('Nama aplikasi [' + name + ']: ').strip() or name
        if args.icon is None:
            icon_value = input('Path icon PNG/JPEG/WebP [' + (icon_value or 'default') + ']: ').strip() or icon_value
    icon = Path(icon_value).expanduser().resolve() if icon_value and icon_value != 'default' else None
    resources = state / 'branding-res'
    branding(source, resources, name, icon)
    workspace = state / 'project'
    copy_project(source, workspace, sdk)
    key, secret = signing_key(state / 'signing', env)
    unsigned = workspace / 'app/build/outputs/apk/release/app-release-unsigned.apk'
    unsigned.unlink(missing_ok=True)
    run([gradle, '--no-daemon', '--console=plain', '--max-workers=2', '-p', workspace,
         '-PCBT_CLI_UNSIGNED=true', '-PCBT_BRANDING_RES_DIR=' + str(resources), ':app:assembleRelease'], env=env)
    if not unsigned.is_file() or unsigned.stat().st_size == 0:
        raise BuildError('Gradle tidak menghasilkan APK rilis baru.')
    tools = sdk / 'build-tools' / config['build_tools']
    slug = re.sub(r'[^a-z0-9]+', '-', name.lower()).strip('-') or 'cbt-kiosk'
    output = Path(args.output).expanduser().resolve() if args.output else ROOT / 'dist/android' / (slug + '-release.apk')
    if output.suffix.lower() != '.apk':
        raise BuildError('Output harus menggunakan ekstensi .apk.')
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=output.parent) as temporary:
        signed = Path(temporary) / 'signed.apk'
        sign_apk(unsigned, signed, tools, key, secret, env)
        metadata = run([tools / 'aapt', 'dump', 'badging', signed], env=env, capture_output=True, text=True).stdout
        with zipfile.ZipFile(signed) as bundle:
            members = {entry.filename: bundle.read(entry) for entry in bundle.infolist()
                       if entry.filename.startswith('res/')}
        verify_packaged_apk(metadata, members, name, icon)
        signed.replace(output)
    saved_file.write_text(json.dumps({'name': name, 'icon': str(icon) if icon else ''}, ensure_ascii=False) + '\n')
    print('APK rilis: ' + str(output))
    print('Kunci signing (backup bersama password): ' + str(key.parent))
    print('Package ID: id.sch.cbt.kiosk; kunci berbeda tidak dapat memperbarui APK lama dengan tanda tangan lain.')


def main():
    parser = argparse.ArgumentParser(description='Build Android CBT-MF tanpa Docker/server.')
    parser.add_argument('command', choices=['doctor', 'setup', 'build'])
    parser.add_argument('--yes', action='store_true', help='Terima lisensi Android SDK saat pemasangan')
    parser.add_argument('--name', help='Nama aplikasi')
    parser.add_argument('--icon', help='Icon PNG/JPEG/WebP, atau default')
    parser.add_argument('--output', help='Berkas APK final')
    args = parser.parse_args()
    if sys.version_info < (3, 9):
        raise BuildError('Python 3.9 atau lebih baru diperlukan.')
    if platform.system() != 'Linux' or platform.machine() != 'x86_64':
        raise BuildError('Builder ini memerlukan Linux x86_64.')
    state = Path(os.environ.get('CBT_ANDROID_STATE_DIR', str(ROOT / '.android-build'))).expanduser().resolve()
    config = project_config(SOURCE)
    if args.command == 'doctor':
        return doctor(SOURCE, state, config)
    state.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (state / 'build.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise BuildError('Build/setup lain masih berjalan pada direktori runtime ini.')
        env, sdk, gradle = ensure_tools(SOURCE, state, config, args.yes)
        if args.command == 'build':
            build(SOURCE, state, config, args, env, sdk, gradle)
        else:
            print('Dependensi build Android siap.')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (BuildError, OSError, ValueError, KeyError, IndexError, ET.ParseError, subprocess.SubprocessError, urllib.error.URLError) as error:
        print('Error: ' + str(error), file=sys.stderr)
        sys.exit(1)
