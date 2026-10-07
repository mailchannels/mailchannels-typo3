# MailChannels Email API for TYPO3

Unreleased transport candidate. **Do not use in production.** No Packagist or TER
release exists. Package name and extension key remain provisional.

The candidate maps native TYPO3/Symfony email to the MailChannels Email API and
checks configuration before ordinary core mail dispatch. It preserves supported
recipient roles, plain/HTML bodies and attachments, uses verified HTTPS, and does
not automatically retry or fall back to SMTP.

Current validation is limited to TYPO3 14.3.7, PHP 8.4.26 and SQLite. See
[configuration and limitations](README.txt) before evaluating it. Support:
dev@mailchannels.com. GPL-2.0-or-later; see [LICENSE](LICENSE).

## Reproduce isolated validation

Requires Docker, Python 3 and Python cryptography 45.0.3. Dependency/image retrieval
uses the network. Mail probes use synthetic credentials, offline or internal Docker
networks, and no provider email. Run from this repository root:

```sh
docker build -t mailchannels-typo3-contract:php84 .
docker run --rm -v "$PWD:/app" -w /app/contract mailchannels-typo3-contract:php84 composer install --no-interaction --prefer-dist --no-progress --no-plugins --no-scripts
python check.py
python tls/run.py
python native/run.py
```

Expected evidence: 155 contract checks, 8 local HTTPS scenarios, and 63 fresh-site
checks. The native runner creates and deletes a disposable site/database; TLS
fixtures remove their containers, network and temporary certificate material.
Exact completion markers matter because TYPO3 may handle exceptions while exiting 0.

Native checks cover full container registration, Fluid rendering, reset token
creation, form finisher execution, stock templates, a stored-file attachment,
and removal/recovery. They do not prove browser submission, upload authorization,
provider delivery or directory publication. JavaScript/browser tests are not included.

## Release work still required

Browser/controller workflows and upload validation, FileReference/multi-file input,
finisher-chain orchestration, upgrades, supported-version/deployment review, durable
queue semantics, company provider validation, publisher/key ownership and release
review remain. Queues are rejected by ordinary preflight; safe queue sending is not
implemented. A failed native password-reset send can replace the stored reset token.
A form finisher handles send failure by cancelling its context and rendering an error.

Public source alone does not register a package or establish TER availability.
