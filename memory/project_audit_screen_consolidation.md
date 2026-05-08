---
name: AI Cyber Audit screen consolidation
description: DONE in v1.9.712 — merged standard + deep dive into one Deep Dive-only screen
type: project
---

Consolidation implemented in v1.9.712 (2026-05-06).

**What was done:**
- Removed the "Internal Config Audit" column and two-column layout entirely
- Deep Dive button is now the sole scan control — full width, no sibling
- Removed SCAN_CFG.standard, MODEL_OPTS.*.standard, scanBtn, vulnModelBadge, modelSel from JS
- Removed "Audit model" selector from AI Settings (kept "AI model" = deep model)
- Removed "Scan type" row from schedule settings (hardcoded to 'deep')
- Existing history entries with type:'standard' still render correctly via fallback label

**Why:** Standard audit was a subset of deep dive; two buttons were confusing.

**How to apply:** Task is complete. No further action needed.
