MailChannels Email API for TYPO3 — unreleased candidate

Composer package:mailchannels/typo3-email-api-candidate
Provisional local extension key:mailchannels_email_api_candidate
Neither name is a registered publisher identity or TER listing. Version0.0.0 in
legacy metadata is a development placeholder, not a published release.
Support:dev@mailchannels.com. GPL-2.0-or-later; see LICENSE.

Current bounds:TYPO3 core14.3.7 and PHP8.4 only. These narrow package constraints
match the available fixtures, not a completed supported-version/deployment matrix.
The package requires Composer-managed Guzzle8/PSR7v3 and Symfony7.4 dependencies.
Classic mode installation and TYPO3's legacy metadata deprecation need review;
do not bundle vendor/ into an extension archive or claim Classic compatibility.

Installation does not configure MAIL routing. The only service is a pre-send guard
that intervenes when MailChannels is selected or an older Mailer retains its
transport. Existing unrelated null/mbox/SMTP settings remain unchanged.

Do not activate this candidate on production. First validate a disposable installed
TYPO3 site, including automatic listener registration, Fluid/body rendering, reset/
form/backend mail, permissions, lifecycle, serialization and all required queues.
The standalone fixture verifies full Composer-site boot, FluidEmail conversion,
native reset tokens, form finishers, stock templates/stored-file attachment and
removal/recovery. It does not verify browser submission or safe queue handling. Direct-mode configuration needs protected server settings for the
API key and exact allowed sender list; there is no admin secret-storage UI.

The guard rejects DSN/spool conflicts and stale credential/policy/routing snapshots
before ordinary core dispatch. Native spool replay can bypass that event; queued
sending is not implemented safely. No automatic retry or SMTP fallback is supplied.
Uncertain failures can follow provider acceptance, so do not resend blindly.
The returned Symfony message ID is local, not proof of provider delivery/identity.

Supported conversion:typed Email routing, subject/custom headers, native plain/HTML
alternatives and documented MIME attachment trees. Raw RFC messages, provider-
restricted preassigned Message-ID and unsupported/ambiguous MIME/header combinations
reject. Trusted server composition must authorize file/stream data sources.

Before publishing:assign a named TYPO3.org maintainer, verify key/package availability,
complete the installed-site and queue requirements, review dependency/security and
release artifacts, broaden version claims only with evidence, then publish source,
Packagist and TER separately and verify public installation/discovery.

Removal and rollback for a disposable reviewed deployment
1. Stop/coordinate mail-producing workers and audit pending queues. Native queue
   replay is not safe/supported by this candidate; do not flush uncertain messages.
2. In the protected server configuration, explicitly select null or another verified
   transport before removing this package. Review DSN/spool overrides too. Preserve
   a protected rollback copy without exposing keys in tickets/logs.
3. Restart/rebuild affected long-lived processes and verify intended routing. Then
   remove the Composer package and rebuild through the normal TYPO3 process.
4. Verify that the package and guard are absent, and the chosen routing still works.
   Remove unused mailchannels-specific key/policy settings under company secret
   handling procedures. Do not automatically restore a historical route over later
   administrator changes.

Native removal tests show null routing is preserved and the full container drops
the guard. If configuration still names the removed ApiTransport, mailer construction
fails explicitly; there is no automatic fallback or configuration rewrite. Recovery
requires an operator to select a valid transport. These tests do not cover concurrent
workers, queue recovery, reinstall/upgrade or every deployment cache strategy.
