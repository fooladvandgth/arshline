# 2.5.1 – Undo reliability and REST hardening

Release date: 2025-09-28

- AI Terminal (هوشیار):
  - Fix UI Undo for navigation actions:
    - open_tab now sets hash + renders, and Undo restores the previous tab hash and explicitly re-renders.
    - open_builder now sets builder/:id + renders forms, and Undo restores the previous tab similarly.
  - Consistently use a robust REST URL builder to prevent 404s under `rest_route` permalink mode for ai/agent, ai/undo, ai/audit.
- Version bump across plugin and dashboard assets to 2.5.1.

Notes:
- UI-only actions are intentionally not persisted to the audit log; the Undo toolbar prioritizes UI-undo, then server undo token, then shows recent audit entries.
