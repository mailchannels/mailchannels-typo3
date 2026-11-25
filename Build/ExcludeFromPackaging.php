<?php
// Tailor exclude list for the TER artefact: set TYPO3_EXCLUDE_FROM_PACKAGING=Build/ExcludeFromPackaging.php
return [
    'directories' => ['.git', '.github', 'Build', 'contract', 'native', 'tls', 'vendor', '.native-work', '__pycache__', '.ci-venv'],
    'files' => ['Dockerfile', 'check.py', '.gitattributes', '.gitignore', '.DS_Store'],
];
