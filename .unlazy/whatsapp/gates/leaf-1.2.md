# Gates: shared registration

OWNS: src/Application/Kernel.php, src/Application/Registration.php, src/Application/Staff.php, src/Application/ApplicationCard.php, src/Application/AccountLinker.php, src/Application/ui.json, tests/WhatsApp/Flow/**

Scope: reuse registration on both channels, confirmed linking and Telegram staff workflow.

- [x] G1: four-language registration, navigation, review, both linking directions, language and unsubscribe pass behavioral tests
  CHECK: node tools/whatsapp/verify.mjs flow
  EXPECT: VERIFIED flow
  EVIDENCE: automatic-evidence=v1; definition-sha256=e04bd073b45079e2f3bc75e398c4377fd104ac7e6d5ef0fdd0914498bfce9cec; exit=0; EXPECT=matched; output-sha256=bf10b327e88d1b3a2f2d24819f749f35c1018fb9cf203e2658af1f0491728d2f; output-bytes=315; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
- [x] G2: existing text values unchanged and linking prevents unconfirmed disclosure or takeover
  EVIDENCE: baseline original-ui.json plus independent review confirm zero changed/deleted existing values in four languages. Independent P1 numeric-confirmation race fixed by request-specific /confirm TOKEN on WhatsApp; A/B competition, expiry, forged old numeric menus, foreign identity and replay tested. Telegram callbacks remain bound to original identity. No unresolved findings.
- [x] G0: ledger has valid fail-capable oracles
  CHECK: node C:/Users/oleg-berger/.codex/skills/unlazy/scripts/gate-lint.mjs .unlazy/whatsapp/gates/leaf-1.2.md
  EXPECT: LINT OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=32059e1038a8a664ab874b2996cbb1e07ae810e0036e6439c6ebfcc44aa5a386; exit=0; EXPECT=matched; output-sha256=6c7adc7a4b03c4b89fa48d69440fd74bb1c15f4b8e93d678e9be5fdb4b92ac46; output-bytes=176; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
