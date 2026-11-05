# Gates: complete local WhatsApp application

Scope: all four implementation leaves integrated and every requested outcome observed.

- [x] G0: root ledger has valid fail-capable oracles
  CHECK: node C:/Users/oleg-berger/.codex/skills/unlazy/scripts/gate-lint.mjs .unlazy/whatsapp/GATES.md
  EXPECT: LINT OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=ac3deb9ebe3106ed194dd3064c550f8a4465bd3870be62fa5819fc9ce0776f8f; exit=0; EXPECT=matched; output-sha256=37f9ef62d672f499c4c1eeeef062186dc3c73dd17cddf3aca4fa0aa1f5f11c71; output-bytes=403; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
- [x] G1: every direct child independently reverified with all manual reviews
  CHECK: node C:/Users/oleg-berger/.codex/skills/unlazy/scripts/gate-check.mjs --root . --cwd . --reverify .unlazy/whatsapp/gates/leaf-1.1.md .unlazy/whatsapp/gates/leaf-1.2.md .unlazy/whatsapp/gates/leaf-1.3.md .unlazy/whatsapp/gates/leaf-1.4.md
  EXPECT: ALL MET
  EVIDENCE: automatic-evidence=v1; definition-sha256=86b32cc518b62b63042d4eda7d58262b8d4376b4ece8200a2d82f4a36f2389ef; exit=0; EXPECT=matched; output-sha256=2d679dd12cc39d6434fb06581897c05df00fb58177368f37d3046a62c46cfd87; output-bytes=9095; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
- [x] G2: complete registration through report and combined-channel scenarios pass composed integration tests
  CHECK: node tools/whatsapp/verify.mjs integration
  EXPECT: VERIFIED integration
  EVIDENCE: automatic-evidence=v1; definition-sha256=e771ae3abc903f92e1b9f8321d84b8d2bcf904caa3e4d60ee0a69a6f7f8fe78d; exit=0; EXPECT=matched; output-sha256=f9f295c6d2f67198f02c649c18c50b02e5e52ed1de1955426412b4a421c43bf3; output-bytes=321; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
- [x] G3: full composer suite preserves Telegram and email behavior
  CHECK: node tools/whatsapp/verify.mjs regression
  EXPECT: VERIFIED regression
  EVIDENCE: automatic-evidence=v1; definition-sha256=a28ae5c826ebb990412dac6bdd866e8222cf635d30eb041589500d349efdeb52; exit=0; EXPECT=matched; output-sha256=41577ed3225abf9909185d69ada036e719d0d721a2394272878105e1c50e6975; output-bytes=890; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
- [x] G4: authorized local WhatsApp demo, reconnect and sampled resource peaks are measured and documented
  EVIDENCE: 2026-11-02 local demo checks all true/email1, three authorized real sends jobs1/2/5 delivered attempts0. Dedicated Docker network disconnect retained job2 pending attempts0; reconnect delivered it, container restart preserved session/parts. Measurements idle/connect/exchange/recovery samples5/20/14/44; valid exchange and recovery windows contain actual send timestamps. Max sampled CPU24.43%, RAM19.3MiB. Earlier mistimed exchange window excluded. Runbook/VERIFICATION document fake TG/mail/translator and actual WhatsApp boundaries.
- [x] G5: security review confirms isolation, private secrets and absence of PII in logs
  EVIDENCE: independent review of clients/runtime/local tools found no remaining privacy issue; fixed recipient allowlist, private Windows ACLs, isolated application/capture DBs, loopback transport and Docker logging none. PHP errors static, upstream content excluded. No production .env read/changed, no real TG/mail calls. Business HTML/private session data ignored by Git/Docker. All source changes stay broadcast-whatsapp.
- [x] G6: every current contract row reconciled, exact leaf leases released after verification and all dispatch waves terminal
  EVIDENCE: all C01-C35 outcomes reconciled with tests, independent reviews, demo and measured live evidence; no omitted or abandoned requirement. ready-1 COMPLETE3/3; all four exact leases released after parent re-verification. User-authorized two installer deletions freed4.62GiB; verified quiescent DockerVHD backup retained privately on D. Authorized full WSL restart restored Docker29.8.0, WhatsAppCONNECTED and OpenClaw user service active. Windows454/3321 and isolated LinuxPHP8.4.26 454/3323 pass. Separate image rebuild is honestly documented as incomplete due disk-full; runtime tested in existing image, no deployment. Code commit c83fc69 only broadcast-whatsapp; main/deepl untouched.
