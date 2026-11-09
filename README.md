# MailChannels Email API for TYPO3

Unreleased transport candidate. **Do not use in production.** No Packagist or TER
release exists. The TER extension key `mailchannels_email_api` is registered;
the Composer package is `mailchannels/typo3-email-api`.

The candidate maps native TYPO3/Symfony email to the MailChannels Email API and
checks configuration before ordinary core mail dispatch. It preserves supported
recipient roles, plain/HTML bodies and attachments, uses verified HTTPS, and does
not automatically retry or fall back to SMTP.

Current validation is limited to TYPO3 14.3.10, PHP 8.4.26 and SQLite. See
[configuration and limitations](README.txt) before evaluating it.
GPL-2.0-or-later; see [LICENSE](LICENSE).

## Queued mail

Queued (spooled) sending is not supported in this release. With the MailChannels transport
selected, a configured `transport_spool_type` or DSN makes the extension refuse to send
rather than queue: replaying a queued message could send it twice, and nothing records
which queued messages the API already accepted. Use the default synchronous mailer.

## Support

This project is supported by the MailChannels team on a best-effort basis. The best way to get support is to file an issue on GitHub: https://github.com/mailchannels/mailchannels-typo3/issues

If you are a MailChannels customer and have a problem with the operation of the MailChannels service, you can get support at https://support.mailchannels.com/

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

The native runner resolves the locally built image tag to its image ID once and records that
identity with the run; it does not require an image ID from another machine.

Expected evidence: 155 contract checks, 8 local HTTPS scenarios, and 178 fresh-site
checks. The native runner creates and deletes a disposable site/database; TLS
fixtures remove their containers, network and temporary certificate material.
Exact completion markers matter because TYPO3 may handle exceptions while exiting 0.

Native checks cover full container registration, Fluid rendering, reset token
creation, form finisher execution, stock templates, stored-file/FileReference attachments and finisher-chain outcomes,
and removal/recovery. They do not prove browser submission, upload authorization,
provider delivery or directory publication. JavaScript/browser tests are not included.

## Native form compatibility

TYPO3 14.3.10 stock mail templates (unchanged since 14.3.7) fail when a FileUpload value is an ObjectStorage:
the core display resolver returns a string while the view helper marks the original
value as iterable. Fluid then rejects the `f:for` input before any API request. The
native suite reproduces this directly in Fluid without the MailChannels transport.
The bug is in core and affects every mail transport.

The extension ships opt-in copies of the 14.3.10 stock email templates that render an
upload field as one value, so several files no longer break the mail. Everything else in
them is unchanged. Add the path to each email finisher in your form definition:

```yaml
finishers:
  - identifier: EmailToReceiver
    options:
      templateRootPaths:
        20: 'EXT:mailchannels_email_api/Resources/Private/Frontend/Templates/Finishers/Email/'
```

Use the same option for `EmailToSender`, or set it once for all forms in your form setup
under `prototypes.standard.finishersDefinition.<Finisher>.options.templateRootPaths`.
Installing the extension does not change any form by itself. The native suite checks that
these templates send stock multi-file mail once with both filenames, exact attachment bytes
and unchanged list and scalar fields, with and without the proposed core patch. Remove the
path once a TYPO3 release fixes the core bug.

A proposed core patch is available in [native/core-display-fix](native/core-display-fix/README.txt).
Run `python native/run.py --test-core-display-fix` to compare it in a separate disposable
site. This mode passes the same 178 checks while requiring stock multi-file plain/HTML
rendering and attachment preservation to succeed. It also checks array and scalar
field rendering. The patch is never installed by the extension; upstream review,
core regression tests and a supported released fix are still needed.

A separate custom-template test verifies two attachments, exact binary bytes and
filenames, disabled uploads and an empty collection. Stock templates pass for a
single persisted FAL/Extbase reference. The real FormRuntime finisher loop is tested
with seeded completed-page state: receiver success followed by confirmation failure
keeps the first acceptance and suppresses later finishers. Rendering the chain again
sends both emails again. There is no durable submission identity or rollback; never
blindly replay an uncertain form submission. No browser upload or field authorization
is exercised by these seeded fixtures.

A 25-check submission probe builds the persisted fixture with the native ArrayFormFactory and
renders it through `FormDefinition::bind()` under native FormRuntime state handling. It covers
the signed `__state` round trip with Unicode values, malformed email and an omitted required
field, missing and tampered state, state sent by GET, and the cached-context rules: under `USER`
or without a content object, a POST is discarded into a fresh first page, and the same signed
fields complete once under `USER_INT` with a form session. It does not dispatch the controller.

A persisted YAML fixture also runs through native Extbase Bootstrap, RequestBuilder,
Dispatcher, FormFrontendController, ArrayFormFactory, validators and native finishers
with stock form/controller templates. Its 20 checks cover generated action/hidden/submit
controls, content-UID namespaces, Unicode payloads, malformed email, missing/tampered
state, signed GET and a cacheable render action under `USER`. Confirmation is observed
in returned markup; only the mailer's downstream API client is replaced by a memory sink.
Native anonymous frontend authentication is initialized for the stock honeypot.
Missing-state POST and signed GET return a first page with a blank decoded form state and no
email or completion (since 14.3.10 each render signs a fresh honeypot name into the state), while stock Fluid re-displays their submitted input after controller forwarding.
This does not prove accepted runtime values, browser sessions, CSRF, cross-user ownership,
HTTP middleware, page-cache responses or cached perform rescheduling.

A separate 24-check HTTP fixture runs Core Application::run from PHP cli-server SAPI globals,
through the stock frontend middleware and native EXTBASEPLUGIN rescheduling. Real PAGE/CONTENT
records render persisted YAML with stock form templates. Multipart submissions exercise a required
single text upload, invalid-email correction with the returned signed session/resource controls,
independent ordinary cookie jars, missing upload/state, server-detected PNG rejection and the
inclusive 1024-byte native size limit. Native EmailToReceiver and Confirmation remain in the flow;
only downstream API capture is replaced with an inert in-memory 202 response.

The HTTP server and client share one offline container, use loopback sockets and finite deadlines,
and publish no host port. The runner retains synthetic payloads, hashed cookie observations,
installed source/schema identities and cleanup diagnostics beside its result log before deleting
its own site and server. Invalid email with a valid upload intentionally persists one FAL file for
correction. Multipart temporary files are distinct from those persistent FAL uploads.

This slice proves the ordinary anonymous single-file workflow on the pinned runtime. It does not
prove cookie-bound CSRF, cross-user file ownership, browser cookie policy, a page-cache hit,
concurrency, expiry or provider delivery. Warm GET is an observation only.
Since TYPO3 14.3.10 the honeypot name is kept in the signed form state, so these submissions
create no frontend cookie or anonymous session; the returned signed form session carries
the workflow. A further 16 HTTP checks submit two distinct text files with invalid email through a standard
multiple upload form and stock Default mail templates. The same submitter reuses its
returned signed controls for a second invalid submission and an email-only correction, without
new file parts. Both indexed FAL files, pointer order and exact bytes remain intact. Stock TYPO3
returns the configured finisher failure marker and a sanitized message_unsupported diagnostic,
with no API payload. The opt-in disposable core patch instead requires one confirmation and one
payload with both filenames in plain/HTML bodies and two exact attachments. Five new requests bring
the HTTP total to 40. Setup and observations are retained separately.
This covers two files on the pinned runtime; multiple:true can use ObjectStorage even for one file.

The explicit core session-fixation TODO and stock multi-file template gate remain unresolved.

## Release work still required

Browser workflows and stronger CSRF/upload ownership guarantees, stock multi-file template
compatibility, upgrades, supported-version/deployment review, durable
queue semantics, company provider validation, publisher/key ownership and release
review remain. Queues are rejected by ordinary preflight; safe queue sending is not
implemented. A failed native password-reset send can replace the stored reset token.
A form finisher handles send failure by cancelling its context and rendering an error.

Public source alone does not register a package or establish TER availability.
