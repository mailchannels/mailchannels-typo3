Proposed TYPO3 core display-value fix — test-only, not installed by this extension

The stock14.3.7 RenderFormValueViewHelper labels an ObjectStorage input as multi-value
although FileUpload processes it into a joined filename string. Default mail HTML
then passes that string to f:for and fails before transport. proposed.patch derives
isMultiValue from the processed display value and preserves both raw and processed
values for callers. It does not change FileUpload placeholder formatting.

Baseline (asserts the known core failure):
  python native/run.py
Proposed fix (requires stock multi-file mail to succeed):
  python native/run.py --test-core-display-fix

Each command installs a fresh locked TYPO314.3.7 site and runs89checks, including
47form checks. The optional patch is applied only to that site's vendor file before
bootstrap. apply.py asserts the original and resulting SHA256 so unreviewed core
source changes cannot silently pass. Both sites and patched vendor trees are removed.
The extension Classes/ and ordinary installations are unchanged.

Differential coverage: two real persisted FAL/Extbase file references in ObjectStorage;
stock plain/HTML filenames and exact attachment bytes; independent core Fluid rendering
without MailChannels transport; array-valued mapped choices and scalar field regression;
custom-template mixed FileReference/File conversion; native finisher ordering/failures.
Other native reset, lifecycle, direct-message and configuration tests run in both modes.

Limitations: seeded input/completed-page state; no browser upload authorization,
localization matrix, other core versions, plugin vendor patch deployment or upstream
core test-suite run. Company contributor should search Forge/Gerrit for duplicates,
port this patch and native regression into current core tests, then submit through
TYPO3's contribution process. No upstream report or patch has been submitted.
Keep stock multi-file compatibility as a release gate until an approved core release
or separately reviewed maintained compatibility solution is used and validated.
