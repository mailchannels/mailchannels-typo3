"""Apply the proposed patch only to the disposable locked fixture site."""
from pathlib import Path
import hashlib
p = Path('/site/vendor/typo3/cms-form/Classes/ViewHelpers/RenderFormValueViewHelper.php')
s = p.read_bytes()
assert hashlib.sha256(s).hexdigest() == 'f3535bab9f5b705f1acc12be98277f16b01be2fef9803ae08cede3ce036830b6', 'Unexpected core source; rebase/review before testing'
s = s.decode()
s = s.replace("            $value = $formRuntime[$element->getIdentifier()];\n", "            $value = $formRuntime[$element->getIdentifier()];\n            $processedValue = $this->formValueResolver->resolveDisplayValue($element, $value, $formRuntime);\n")
s = s.replace("'processedValue' => $this->formValueResolver->resolveDisplayValue($element, $value, $formRuntime)", "'processedValue' => $processedValue")
s = s.replace("'isMultiValue' => is_iterable($value)", "'isMultiValue' => is_iterable($processedValue)")
assert hashlib.sha256(s.encode()).hexdigest() == 'af30d5e97b983e5e9926f52beb413bc62695a29e31c3cc48e6eaeeccc5d450b1'
p.write_text(s)
print('TYPO3_PROPOSED_CORE_DISPLAY_FIX_APPLIED')
