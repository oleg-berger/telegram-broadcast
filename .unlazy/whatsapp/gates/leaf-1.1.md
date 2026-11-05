# Gates: persistence

OWNS: src/Application/Store.php, src/Infrastructure/SqliteStore.php, migrations/004_whatsapp.sql, tests/WhatsApp/Store/**

Scope: lossless identity migration, durable linking, snapshots and delivery state.

- [x] G1: migration, identity isolation, linking constraints, snapshots, catchup and receipt persistence pass behavioral tests
  CHECK: node tools/whatsapp/verify.mjs store
  EXPECT: VERIFIED store
  EVIDENCE: automatic-evidence=v1; definition-sha256=a18bff5ca89a54bdce65fe98d2e924c39878a8d88bcc6c8a02357b069eaf6040; exit=0; EXPECT=matched; output-sha256=c90951ac004964d9fcdf37c3b7d449600872e289e9f676918a76d51596e5a9d5; output-bytes=316; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
- [x] G2: migration and linking invariants independently reviewed, including existing records and races
  EVIDENCE: root and independent review_whatsapp_spike reviewed additive 004/005, PN/LID aliases, identity collisions, one-use linking, snapshots, early/batched receipts and effective contact ownership. P2 resubmission defect fixed with reservation chain/cycle/rollback tests. Export explicitly separates person ID and channel addresses; importer independently reviewed. No unresolved findings.
- [x] G0: ledger has valid fail-capable oracles
  CHECK: node C:/Users/oleg-berger/.codex/skills/unlazy/scripts/gate-lint.mjs .unlazy/whatsapp/gates/leaf-1.1.md
  EXPECT: LINT OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=ff93589b14359fd86431cd76ae4034e9ec040eff8b571a2aa881f7943d263612; exit=0; EXPECT=matched; output-sha256=f2c59ad9eadceb6ade8d69b883bdc0e97bddd33c4a968050de9cc35efce8d7e5; output-bytes=176; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
