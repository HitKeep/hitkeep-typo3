#!/usr/bin/env python3
"""Install the actual local archive in a clean, disposable TYPO3 test project."""
import hashlib
import json
import pathlib
import shutil
import subprocess
import sys
import tempfile
import zipfile

source = pathlib.Path(__file__).resolve().parents[1]
archive = pathlib.Path(sys.argv[1]).resolve()
fixture = pathlib.Path(tempfile.mkdtemp(prefix='hitkeep-typo3-package-'))
manifest = json.loads((source / 'composer.json').read_text())
with zipfile.ZipFile(archive) as package:
    names = package.namelist()
    assert 'Classes/UserFunc/Tracker.php' in names
    assert 'Configuration/Services.yaml' in names
    assert 'Configuration/Sets/HitKeep/setup.typoscript' in names
    assert not any(name.startswith(('vendor/', 'Tests/', 'public/', 'var/', '.git/', 'tools/')) for name in names)
    assert not any(name.endswith(('.pem', '.log', '.sqlite')) for name in names)
package = {key: manifest[key] for key in ['name', 'type', 'require', 'autoload', 'extra']}
package.update(version='0.1.0', dist={'type': 'zip', 'url': 'file:///dist/' + archive.name})
project = {
    'name': 'hitkeep/typo3-install-fixture', 'type': 'project',
    'repositories': [{'type': 'package', 'package': package}],
    'require': {'hitkeep/typo3': '0.1.0'},
    'require-dev': manifest['require-dev'], 'autoload-dev': manifest['autoload-dev'],
    'config': manifest['config'], 'extra': {'typo3/cms': {'web-dir': 'public'}},
}
(fixture / 'composer.json').write_text(json.dumps(project, indent=2) + '\n')
shutil.copytree(source / 'Tests', fixture / 'Tests')
shutil.copy(source / 'phpunit.xml', fixture / 'phpunit.xml')
command = ['docker', 'run', '--rm', '--entrypoint', 'sh', '-v', str(fixture) + ':/app', '-v', str(archive.parent) + ':/dist:ro', '-w', '/app', 'ddev/ddev-webserver:v1.25.4', '-c', 'COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist && vendor/bin/phpunit -c phpunit.xml']
print('Fixture:', fixture, flush=True)
print('Archive SHA256:', hashlib.sha256(archive.read_bytes()).hexdigest(), flush=True)
tested = subprocess.run(command, check=True, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
print(tested.stdout, flush=True)
assert 'OK (' in tested.stdout or 'OK, but some tests were skipped!' in tested.stdout, 'PHPUnit must actually finish; a bootstrap exit is not a passing suite'
# Composer must not modify the CMS content database on package removal/reinstall.
databases = list((fixture / 'public').rglob('*.sqlite'))
assert databases, 'Native functional fixture must create SQLite content databases'
before = {str(path): hashlib.sha256(path.read_bytes()).hexdigest() for path in databases}
command[-1] = 'COMPOSER_ALLOW_SUPERUSER=1 composer remove hitkeep/typo3 --no-interaction --no-scripts && COMPOSER_ALLOW_SUPERUSER=1 composer require hitkeep/typo3:0.1.0 --no-interaction --no-scripts'
# Keep the framework/core installed so package removal is isolated to the adapter.
project['require']['typo3/cms-frontend'] = manifest['require']['typo3/cms-frontend']
(fixture / 'composer.json').write_text(json.dumps(project, indent=2) + '\n')
subprocess.run(command, check=True)
assert before == {str(path): hashlib.sha256(path.read_bytes()).hexdigest() for path in databases}
print('PASS: clean archive install/render; package removal/reinstall leaves fixture database bytes unchanged.')
print('Limit: no-scripts removal/reinstall is Composer dependency lifecycle proof; site-set removal/purge rendering is tested separately.')
