import argparse
import hashlib
import importlib.util
import io
import os
from pathlib import Path
import re
import stat
import struct
import subprocess
import tempfile
import unittest
import zipfile
from unittest.mock import patch
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
MODULE = ROOT / 'scripts' / 'android_build.py'
PNG = bytes.fromhex('89504e470d0a1a0a0000000d4948445200000001000000010806000000')
NAME = 'Ujian "Mandiri" & O\'Brien'
# The label as aapt prints it in `dump badging`: quotes and backslashes escaped.
AAPT_LABEL = 'Ujian \\"Mandiri\\" & O\'Brien'
# Complete 64x64 red images; VP8 stores dimensions directly, VP8L stores minus one.
WEBP_LOSSY = bytes.fromhex(
    '524946465600000057454250565038204a0000001004009d012a400040003e6d3698'
    '49242322a1220800800d8969007600fc0000206ea6a00af10b720000fef09b43fffe'
    '4172c2eb91affffc80ff901ff203ffe3df16e3a3c71080000000')
WEBP_LOSSLESS = bytes.fromhex(
    '5249464622000000574542505650384c150000002f3fc00f000710fd8ffe078084f0ff'
    'bd16d1ffd40f00')


def png(width, height, color_type=6):
    signature = b'\x89PNG\r\n\x1a\n'
    header = b'IHDR' + struct.pack('>IIBBBBB', width, height, 8, color_type, 0, 0, 0)
    return signature + struct.pack('>I', len(header)) + header + b'\x00\x00\x00\x00IEND'


def jpeg(width, height):
    payload = struct.pack('>BHH', 8, height, width)
    encoded = payload + b'\x01' * (17 - 1 - len(payload))
    return b'\xff\xd8' + b'\xff\xc0' + struct.pack('>H', len(encoded) + 2) + encoded


def webp(width, height):
    payload = b'\x10\x00\x00\x00'
    payload += (width - 1).to_bytes(3, 'little') + (height - 1).to_bytes(3, 'little')
    chunk = b'VP8X' + struct.pack('<I', len(payload)) + payload
    return b'RIFF' + struct.pack('<I', 4 + len(chunk)) + b'WEBP' + chunk


def badging(name_value, package='id.sch.cbt.kiosk', icon_path='res/Q6.png', debuggable=False):
    lines = ["package: name='%s' versionCode='2' versionName='1.1.0'" % package]
    if icon_path:
        lines += ["application-icon-%d:'%s'" % (dpi, icon_path) for dpi in [160, 240]]
    lines.append("application-label:'%s'" % name_value)
    if debuggable:
        lines.append("application-debuggable")
    lines.append("application: label='%s' icon='%s'" % (name_value, icon_path or ''))
    return '\n'.join(lines) + '\n'


class AndroidBuildTest(unittest.TestCase):
    def setUp(self):
        self.assertTrue(MODULE.exists(), 'Android builder is missing')
        spec = importlib.util.spec_from_file_location('android_build', MODULE)
        self.builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.builder)
        self.scratch = tempfile.TemporaryDirectory(prefix='cbt-android-test-')
        self.addCleanup(self.scratch.cleanup)
        self.tmp = Path(self.scratch.name)

    def source(self):
        source = self.tmp / 'source'
        (source / 'app/src/main/res/values').mkdir(parents=True)
        (source / 'gradle/wrapper').mkdir(parents=True)
        (source / 'app/src/main/res/values/strings.xml').write_text(
            '<resources><string name="app_name">CBT-MF Kiosk</string>'
            '<string name="setup_title">CBT-MF Kiosk</string>'
            '<string name="home_launcher_title">Jadikan CBT-MF sebagai Beranda</string>'
            '<string name="home_launcher_message">Kebijakan CBT-MF. Pilih CBT-MF Kiosk.</string></resources>')
        for name in ['settings.gradle.kts', 'build.gradle.kts', 'gradle.properties', 'app/proguard-rules.pro']:
            (source / name).write_text('original')
        (source / 'app/build.gradle.kts').write_text('compileSdk = 36\nbuildToolsVersion = "35.0.0"\n')
        (source / 'gradle/wrapper/gradle-wrapper.properties').write_text(
            'distributionUrl=https\\://services.gradle.org/distributions/gradle-8.13-bin.zip\n')
        (source / 'local.properties').write_text('sdk.dir=/developer/private-sdk\n')
        return source

    def test_project_versions_come_from_gradle_project(self):
        config = self.builder.project_config(self.source())
        self.assertEqual(36, config['sdk'])
        self.assertEqual('35.0.0', config['build_tools'])
        self.assertEqual('8.13', config['gradle_version'])

    def test_branding_quotes_names_and_copies_icon_without_changing_source(self):
        source = self.source()
        icon = self.tmp / 'my school.png'
        icon.write_bytes(PNG)
        resources = self.tmp / 'branding'
        name = 'Ujian "Sekolah" & O\'Brien'
        self.builder.branding(source, resources, name, icon)
        strings = {node.attrib['name']: node.text for node in ET.parse(resources / 'values/branding.xml').getroot()}
        self.assertIn('& O\'Brien', strings['app_name'])
        self.assertIn('\\"Sekolah\\"', strings['app_name'])
        self.assertEqual('@drawable/cbt_custom_icon', strings['cbt_launcher_icon'])
        self.assertEqual(PNG, (resources / 'drawable-nodpi/cbt_custom_icon.png').read_bytes())
        self.assertIn('CBT-MF Kiosk', (source / 'app/src/main/res/values/strings.xml').read_text())

    def test_invalid_name_or_icon_is_rejected(self):
        source = self.source()
        for name in ['', 'line\nbreak', 'x' * 81]:
            with self.subTest(name=name), self.assertRaises(self.builder.BuildError):
                self.builder.branding(source, self.tmp / 'res', name, None)
        invalid = self.tmp / 'fake.png'
        invalid.write_text('not an image')
        with self.assertRaises(self.builder.BuildError):
            self.builder.branding(source, self.tmp / 'res', 'School', invalid)

    def test_branding_replaces_each_brand_mention_once(self):
        source = self.source()
        resources = self.tmp / 'branding'
        self.builder.branding(source, resources, 'CBT-MF Sija', None)
        strings = {node.attrib['name']: node.text for node in ET.parse(resources / 'values/branding.xml').getroot()}
        self.assertEqual('"Jadikan CBT-MF Sija sebagai Beranda"', strings['home_launcher_title'])
        self.assertEqual('"Kebijakan CBT-MF Sija. Pilih CBT-MF Sija."', strings['home_launcher_message'])

    def test_image_dimensions_read_png_jpeg_webp(self):
        self.assertEqual((1, 1), self.builder.image_dimensions(PNG))
        self.assertEqual((64, 64), self.builder.image_dimensions(png(64, 64)))
        self.assertEqual((7, 9), self.builder.image_dimensions(jpeg(7, 9)))
        self.assertEqual((12, 34), self.builder.image_dimensions(webp(12, 34)))
        self.assertIsNone(self.builder.image_dimensions(b'not an image'))

    def test_webp_dimensions_agree_for_lossy_lossless_and_extended_headers(self):
        for data in [WEBP_LOSSY, WEBP_LOSSLESS, webp(64, 64)]:
            with self.subTest(chunk=data[12:16]):
                self.assertEqual((64, 64), self.builder.image_dimensions(data))

    def test_packaged_metadata_accepts_aapt_escaped_label_and_icon(self):
        icon = self.tmp / 'photo.png'
        icon.write_bytes(PNG)
        members = {'res/Q6.png': png(1, 1, color_type=2)}  # aapt can re-encode the PNG
        self.builder.verify_packaged_apk(badging(AAPT_LABEL), members, NAME, icon)

    def test_packaged_metadata_accepts_default_branding(self):
        self.builder.verify_packaged_apk(
            badging('CBT-MF Kiosk'), {'res/Q6.png': b'packaged image bytes'}, 'CBT-MF Kiosk')

    def test_debuggable_word_in_label_is_not_a_debug_flag(self):
        for name in ['application-debuggable', 'Ujian application-debuggable Sekolah',
                     'Ujian\u2028application-debuggable\u2028Sekolah']:
            with self.subTest(name=name):
                self.builder.verify_packaged_apk(badging(name), {'res/Q6.png': PNG}, name)
                with self.assertRaises(self.builder.BuildError):
                    self.builder.verify_packaged_apk(
                        badging(name, debuggable=True), {'res/Q6.png': PNG}, name)

    def test_custom_webp_icon_can_match_another_webp_header_variant(self):
        icon = self.tmp / 'photo.webp'
        icon.write_bytes(WEBP_LOSSY)
        self.builder.verify_packaged_apk(
            badging(AAPT_LABEL, icon_path='res/Q6.webp'), {'res/Q6.webp': WEBP_LOSSLESS}, NAME, icon)

    def test_packaged_metadata_rejects_the_wrong_name_package_escaping_or_debuggable(self):
        icon = self.tmp / 'photo.png'
        icon.write_bytes(PNG)
        members = {'res/Q6.png': PNG}
        for name_value, package, debuggable in [('CBT-MF Kiosk', 'id.sch.cbt.kiosk', False),
                                                (NAME, 'com.other.app', False),
                                                (NAME, 'id.sch.cbt.kiosk', True),
                                                ('Ujian \\nMandiri', 'id.sch.cbt.kiosk', False)]:
            with self.subTest(name=name_value), self.assertRaises(self.builder.BuildError):
                self.builder.verify_packaged_apk(badging(name_value, package, debuggable=debuggable),
                                                 members, NAME, icon)

    def test_packaged_metadata_requires_referenced_icon_entries(self):
        metadata = badging('CBT-MF Kiosk', icon_path='res/shrunk-icon.png')
        with self.assertRaises(self.builder.BuildError):
            self.builder.verify_packaged_apk(metadata, {}, 'CBT-MF Kiosk')
        self.builder.verify_packaged_apk(metadata, {'res/shrunk-icon.png': b'i'}, 'CBT-MF Kiosk')

    def test_custom_icon_is_verified_against_the_cross_check_by_reencoded_resource(self):
        icon = self.tmp / 'photo.png'
        icon.write_bytes(png(64, 64))
        members = {'res/Q6.png': png(64, 64, color_type=2)}  # re-encoded PNG, same dimensions
        self.builder.verify_packaged_apk(badging(AAPT_LABEL), members, NAME, icon)
        members = {'res/Q6.png': png(300, 300, color_type=2)}
        with self.assertRaises(self.builder.BuildError):
            self.builder.verify_packaged_apk(badging(AAPT_LABEL), members, NAME, icon)

    def test_gradle_failure_does_not_publish_or_touch_previous_output(self):
        source = self.source()
        state = self.tmp / 'state'
        unsigned = state / 'project/app/build/outputs/apk/release/app-release-unsigned.apk'
        unsigned.parent.mkdir(parents=True)
        unsigned.write_bytes(b'stale unsigned apk')
        sdk = self.tmp / 'sdk'
        self.sdk(sdk)
        output = self.tmp / 'dist' / 'form.apk'
        output.parent.mkdir()
        output.write_bytes(b'previous release apk')
        icon = self.tmp / 'photo.png'
        icon.write_bytes(PNG)
        args = argparse.Namespace(name='Pejabat & O\'Brien', icon=str(icon), output=str(output), yes=False)

        def run(args, **kwargs):
            text = [str(part) for part in args]
            if any('assembleRelease' in part for part in text):
                raise subprocess.CalledProcessError(1, args)
            if 'keytool' in text and '-genkeypair' in text:
                key = Path(text[text.index('-keystore') + 1])
                key.write_bytes(b'key')
            return subprocess.CompletedProcess(args, 0)

        with patch.object(self.builder, 'run', side_effect=run):
            with self.assertRaises(subprocess.SubprocessError):
                self.builder.build(source, state, self.builder.project_config(source), args, {}, sdk, self.tmp / 'gradle')
        self.assertEqual(b'previous release apk', output.read_bytes())
        self.assertFalse((state / 'project/app/build/outputs/apk/release/app-release-unsigned.apk').exists())
        self.assertFalse((state / 'branding.json').exists())

    def test_build_copy_does_not_copy_developer_sdk_or_signing_files(self):
        source = self.source()
        (source / 'secret.jks').write_text('private')
        destination = self.tmp / 'workspace'
        self.builder.copy_project(source, destination, self.tmp / 'SDK with spaces')
        self.assertIn('SDK\\ with\\ spaces', (destination / 'local.properties').read_text())
        self.assertEqual('sdk.dir=/developer/private-sdk\n', (source / 'local.properties').read_text())
        self.assertFalse((destination / 'secret.jks').exists())
        self.assertTrue((destination / 'app/src/main/res/values/strings.xml').exists())

    def test_failed_checksum_cannot_replace_a_cached_download(self):
        target = self.tmp / 'tool.zip'
        target.write_bytes(b'old artifact')
        with patch.object(self.builder, 'open_url', return_value=io.BytesIO(b'corrupted')):
            with self.assertRaises(self.builder.BuildError):
                self.builder.download('https://example.test/tool.zip', target, hashlib.sha256(b'correct').hexdigest())
        self.assertEqual(b'old artifact', target.read_bytes())

    def test_download_is_reused_only_when_checksum_matches(self):
        target = self.tmp / 'tool.zip'
        data = b'verified artifact'
        with patch.object(self.builder, 'open_url', return_value=io.BytesIO(data)):
            self.builder.download('https://example.test/tool.zip', target, hashlib.sha256(data).hexdigest())
        with patch.object(self.builder, 'open_url', side_effect=AssertionError('must use verified cache')):
            self.builder.download('https://example.test/tool.zip', target, hashlib.sha256(data).hexdigest())
        self.assertEqual(data, target.read_bytes())

    def test_signing_reuses_key_and_keeps_password_private(self):
        signing = self.tmp / 'signing'

        def keytool(args, **kwargs):
            if '-genkeypair' in args:
                Path(args[args.index('-keystore') + 1]).write_bytes(b'test key')
            return subprocess.CompletedProcess(args, 0)

        with patch.object(self.builder, 'run', side_effect=keytool):
            key, secret = self.builder.signing_key(signing, {})
            password = secret.read_bytes()
            key.write_bytes(b'existing local key')
            again, _ = self.builder.signing_key(signing, {})
        self.assertEqual(key, again)
        self.assertEqual(b'existing local key', key.read_bytes())
        self.assertEqual(password, secret.read_bytes())
        self.assertEqual(0o600, stat.S_IMODE(secret.stat().st_mode))
        self.assertEqual(0o700, stat.S_IMODE(signing.stat().st_mode))

    def test_incomplete_signing_pair_is_not_overwritten(self):
        signing = self.tmp / 'signing'
        signing.mkdir()
        (signing / 'release.p12').write_bytes(b'user key')
        with self.assertRaises(self.builder.BuildError):
            self.builder.signing_key(signing, {})
        self.assertEqual(b'user key', (signing / 'release.p12').read_bytes())
        self.assertFalse((signing / 'password').exists())

    def sdk(self, path, executable=True):
        targets = ['platforms/android-36/android.jar'] + ['build-tools/35.0.0/' + tool for tool in ['aapt', 'apksigner', 'zipalign']]
        for name in targets:
            target = path / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(b'fixture')
            target.chmod(0o755 if executable and not name.endswith('.jar') else 0o644)

    def archive(self, name, content=b'#!/bin/sh\nexit 0\n'):
        data = io.BytesIO()
        with zipfile.ZipFile(data, 'w') as bundle:
            entry = zipfile.ZipInfo(name)
            entry.external_attr = 0o100755 << 16
            bundle.writestr(entry, content)
        return data.getvalue()

    def test_sdk_check_rejects_tools_that_cannot_execute(self):
        sdk = self.tmp / 'sdk'
        self.sdk(sdk, executable=False)
        missing = self.builder.sdk_missing(sdk, self.builder.project_config(self.source()))
        self.assertIn('apksigner 35.0.0', missing)
        self.assertIn('zipalign 35.0.0', missing)

    def test_missing_sdk_is_installed_and_rechecked(self):
        source = self.source()
        config = self.builder.project_config(source)
        state, sdk = self.tmp / 'state', self.tmp / 'sdk'
        sdk.mkdir()
        archive = self.archive('cmdline-tools/bin/sdkmanager')

        def manager(args, **kwargs):
            if '--install' in args:
                self.sdk(sdk)
            return subprocess.CompletedProcess(args, 0)

        with patch.object(self.builder, 'find_jdk', return_value=self.tmp / 'jdk'), \
             patch.object(self.builder, 'find_sdk', return_value=sdk), \
             patch.object(self.builder, 'find_gradle', return_value=self.tmp / 'gradle'), \
             patch.object(self.builder, 'SDK_TOOLS_SHA256', hashlib.sha256(archive).hexdigest()), \
             patch.object(self.builder, 'open_url', return_value=io.BytesIO(archive)), \
             patch.object(self.builder, 'run', side_effect=manager):
            env, selected, _ = self.builder.ensure_tools(source, state, config, True)
        self.assertEqual(sdk, selected)
        self.assertEqual(str(sdk), env['ANDROID_HOME'])
        self.assertEqual([], self.builder.sdk_missing(sdk, config))
        self.assertTrue(os.access(sdk / 'cmdline-tools/latest/bin/sdkmanager', os.X_OK))

    def test_missing_gradle_is_downloaded_at_the_project_version(self):
        source = self.source()
        config = self.builder.project_config(source)
        state, sdk = self.tmp / 'state', self.tmp / 'sdk'
        self.sdk(sdk)
        archive = self.archive('gradle-8.13/bin/gradle')

        def response(url):
            if url.endswith('.sha256'):
                return io.BytesIO(hashlib.sha256(archive).hexdigest().encode())
            return io.BytesIO(archive)

        with patch.object(self.builder, 'find_jdk', return_value=self.tmp / 'jdk'), \
             patch.object(self.builder, 'find_sdk', return_value=sdk), \
             patch.object(self.builder.shutil, 'which', return_value=None), \
             patch.dict(os.environ, {'GRADLE_USER_HOME': str(self.tmp / 'empty-cache')}), \
             patch.object(self.builder, 'open_url', side_effect=response):
            _, _, gradle = self.builder.ensure_tools(source, state, config, False)
        self.assertEqual(state / 'gradle/gradle-8.13/bin/gradle', gradle)
        self.assertTrue(os.access(gradle, os.X_OK))

    def test_archive_cannot_write_outside_tool_directory(self):
        archive = self.tmp / 'bad.zip'
        archive.write_bytes(self.archive('../escaped'))
        with self.assertRaises(self.builder.BuildError):
            self.builder.unpack_zip(archive, self.tmp / 'extract')
        self.assertFalse((self.tmp / 'escaped').exists())


class AndroidCliTest(unittest.TestCase):
    def test_build_app_alias_forwards_options_without_server_root_or_env(self):
        with tempfile.TemporaryDirectory() as temporary:
            scripts = Path(temporary) / 'scripts'
            scripts.mkdir()
            cli = scripts / 'cbt.sh'
            cli.write_text((ROOT / 'scripts/cbt.sh').read_text())
            (scripts / 'cbt-android.sh').write_text(
                '#!/bin/bash\nprintf "ANDROID_ARGS"\nprintf " <%s>" "$@"\nprintf "\\n"\n')
            result = subprocess.run(
                ['bash', str(cli), 'build-app', '--name', 'Sekolah Mandiri',
                 '--icon', '/tmp/my icon.webp', '--output', '/tmp/release.apk', '--yes'],
                capture_output=True, text=True, timeout=20)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn(
                'ANDROID_ARGS <build> <--name> <Sekolah Mandiri> <--icon> </tmp/my icon.webp>'
                ' <--output> </tmp/release.apk> <--yes>', result.stdout)

    def test_interactive_quick_action_dispatches_android_build(self):
        with tempfile.TemporaryDirectory() as temporary:
            scripts = Path(temporary) / 'scripts'
            scripts.mkdir()
            cli = scripts / 'cbt.sh'
            script = (ROOT / 'scripts/cbt.sh').read_text()
            # Only bypass the root boundary in this fixture; execute the actual menu and handlers.
            guard = ('[ "$EUID" -eq 0 ] || die "Script ini harus dijalankan sebagai root (gunakan sudo).\n'
                     'Contoh: sudo bash scripts/cbt.sh"')
            self.assertIn(guard, script)
            cli.write_text(script.replace(guard, ':', 1))
            (scripts / 'cbt-android.sh').write_text(
                '#!/bin/bash\nprintf "ANDROID_ARGS"\nprintf " <%s>" "$@"\nprintf "\\n"\n')
            first = subprocess.run(['bash', str(cli)], input='00\n',
                                   capture_output=True, text=True, timeout=20)
            self.assertEqual(0, first.returncode, first.stderr)
            quick_actions = first.stdout.split('AKSI CEPAT', 1)[1]
            entry = re.search(r'^\s*\[(\d+)\]\s+build-app\s+.*Build App Android', quick_actions, re.M)
            self.assertIsNotNone(entry, first.stdout)
            selected = subprocess.run(['bash', str(cli)], input=entry.group(1) + '\n\n00\n',
                                      capture_output=True, text=True, timeout=20)
            self.assertEqual(0, selected.returncode, selected.stderr)
            self.assertEqual(1, selected.stdout.count('ANDROID_ARGS <build>'))
            self.assertIn('Selesai.', selected.stdout)

    def test_android_help_runs_without_server_root_or_env(self):
        result = subprocess.run(['bash', str(ROOT / 'scripts/cbt.sh'), 'android', '--help'],
                                capture_output=True, text=True, timeout=20)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('android build', result.stdout)
        self.assertIn('--name', result.stdout)
        self.assertIn('--icon', result.stdout)

    def test_doctor_does_not_create_runtime_or_require_server_env(self):
        with tempfile.TemporaryDirectory() as temporary:
            state = Path(temporary) / 'absent-state'
            result = subprocess.run(['bash', str(ROOT / 'scripts/cbt.sh'), 'android', 'doctor'],
                capture_output=True, text=True, timeout=30,
                env=dict(os.environ, CBT_ANDROID_STATE_DIR=str(state)))
            self.assertIn(result.returncode, [0, 1], result.stderr)
            self.assertIn('JDK 21:', result.stdout)
            self.assertIn('Android SDK:', result.stdout)
            self.assertFalse(state.exists())

    def test_bootstrap_installs_missing_python_then_delegates_setup(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            binary = folder / 'bin'
            binary.mkdir()
            wrapper = folder / 'cbt-android.sh'
            wrapper.write_text((ROOT / 'scripts/cbt-android.sh').read_text())
            for name in ['bash', 'uname', 'dirname']:
                import shutil
                (binary / name).symlink_to(shutil.which(name))
            (binary / 'sudo').write_text('#!/bin/bash\nexec "$@"\n')
            (binary / 'apt-get').write_text('''#!/bin/bash
printf '%s\\n' "$*" >> "$BOOTSTRAP_LOG"
if [ "$1" = install ]; then
    printf '#!/bin/bash\\nprintf "PYTHON_BOOTSTRAPPED\\\\n"\\n' > "$BOOTSTRAP_BIN/python3"
    /usr/bin/chmod +x "$BOOTSTRAP_BIN/python3"
fi
''')
            for name in ['sudo', 'apt-get']:
                (binary / name).chmod(0o755)
            env = dict(os.environ, PATH=str(binary), BOOTSTRAP_LOG=str(folder / 'install.log'), BOOTSTRAP_BIN=str(binary))
            env.pop('SUDO_USER', None)
            result = subprocess.run(['/bin/bash', str(wrapper), 'setup'], capture_output=True, text=True, timeout=20, env=env)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn('PYTHON_BOOTSTRAPPED', result.stdout)
            self.assertIn('install -y python3 ca-certificates', (folder / 'install.log').read_text())

    def test_doctor_with_missing_python_never_installs_packages(self):
        with tempfile.TemporaryDirectory() as temporary:
            binary = Path(temporary)
            import shutil
            (binary / 'uname').symlink_to(shutil.which('uname'))
            (binary / 'apt-get').write_text('#!/bin/bash\ntouch "$INSTALL_MARKER"\n')
            (binary / 'apt-get').chmod(0o755)
            marker = binary / 'installed'
            env = dict(os.environ, PATH=str(binary), INSTALL_MARKER=str(marker))
            env.pop('SUDO_USER', None)
            result = subprocess.run(['/bin/bash', str(ROOT / 'scripts/cbt-android.sh'), 'doctor'],
                                    capture_output=True, text=True, timeout=20, env=env)
            self.assertEqual(1, result.returncode)
            self.assertIn('Python 3', result.stderr)
            self.assertFalse(marker.exists())


class NativeSigningTest(unittest.TestCase):
    def test_generated_key_signs_and_verifies_an_installable_apk(self):
        spec = importlib.util.spec_from_file_location('android_build_native', MODULE)
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        with tempfile.TemporaryDirectory(prefix='cbt-native-sign-') as temporary:
            folder = Path(temporary)
            config = builder.project_config(ROOT / 'cbt-kiosk-app')
            jdk = builder.find_jdk(folder)
            sdk = builder.find_sdk(ROOT / 'cbt-kiosk-app', folder, config)
            if not jdk or builder.sdk_missing(sdk, config):
                self.skipTest('Native signing test needs JDK21 and Android SDK; other tests use fixtures')
            tools = sdk / 'build-tools' / config['build_tools']
            manifest = folder / 'AndroidManifest.xml'
            manifest.write_text('''<manifest xmlns:android="http://schemas.android.com/apk/res/android"
                package="id.sch.cbt.kiosk" android:versionCode="1" android:versionName="1">
                <uses-sdk android:minSdkVersion="28" android:targetSdkVersion="36"/>
                <application android:label="Signing fixture" android:hasCode="false"/>
                </manifest>''')
            unsigned = folder / 'unsigned.apk'
            signed = folder / 'signed.apk'
            env = dict(os.environ, JAVA_HOME=str(jdk), PATH=str(jdk / 'bin') + ':' + os.environ.get('PATH', ''))
            builder.run([tools / 'aapt', 'package', '-f', '-M', manifest,
                         '-I', sdk / 'platforms/android-36/android.jar', '-F', unsigned], env=env)
            key, secret = builder.signing_key(folder / 'signing', env)
            builder.sign_apk(unsigned, signed, tools, key, secret, env)
            result = builder.run([tools / 'apksigner', 'verify', '--print-certs', signed], env=env, capture_output=True, text=True)
            self.assertIn('CN=CBT Local Builder', result.stdout)
            self.assertTrue(signed.is_file())


if __name__ == '__main__':
    unittest.main()
