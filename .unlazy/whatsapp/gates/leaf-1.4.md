# Gates: runtime integration

OWNS: src/Application/Worker.php, src/Infrastructure/Runtime/Config.php, src/Infrastructure/Runtime/DatabaseReset.php, src/Infrastructure/Runtime/CommandMenu.php, bin/console, tools/whatsapp/**, tests/WhatsApp/Integration/**, docs/whatsapp/APPLICATION.md, VERIFICATION.md, .env.example, compose.whatsapp.yaml

Scope: durable independent delivery, safe runtime and isolated local application.

- [x] G1: composed worker, outage recovery, unknown sends, comments, restart, locks and config pass behavioral tests
  CHECK: node tools/whatsapp/verify.mjs integration
  EXPECT: VERIFIED integration
  EVIDENCE: automatic-evidence=v1; definition-sha256=e771ae3abc903f92e1b9f8321d84b8d2bcf904caa3e4d60ee0a69a6f7f8fe78d; exit=0; EXPECT=matched; output-sha256=cd6376bd7bb6e32ac1ef9914c50cde99ba70262865797eb4a19af6d5dcf68162; output-bytes=321; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
- [x] G2: local runbook, migration backup/restore and failure handling independently reviewed
  EVIDENCE: root plus review_whatsapp_spike reviewed Worker atomic unknown/state notifications, runtime Config, binconsole maintenance locks, LocalStand and runbook. Fault injection proves rollback then one restart alert without resend. Local poll outage no longer blocks worker; init locks all three writers before empty-state inspection. Existing import/export adapted and independently reviewed. prepare/demo/listen/status succeeded with isolated DB; migration backup exists. No unresolved findings.
- [x] G0: ledger has valid fail-capable oracles
  CHECK: node C:/Users/oleg-berger/.codex/skills/unlazy/scripts/gate-lint.mjs .unlazy/whatsapp/gates/leaf-1.4.md
  EXPECT: LINT OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=07ff870a5266c3f41faafa2734285fd8da6c6e1c3a90e3a6bed06f0007fe4adc; exit=0; EXPECT=matched; output-sha256=4e8ef160dc3287572fb83521e5894266f2b340d824af4da6f17a1bfcae0711ad; output-bytes=176; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
