---
name: motored-module-mi_garage
description: Work on Motored Mi Garage account routes, saved motorcycles, browser synchronization and privacy.
---

# Mi Garage

Read `src/Modules/MiGarage/README.md` first. Preserve the REST paths, account
endpoint and user meta key. Consume motorcycle profiles from Compatibility.
Use native user metadata and authenticated REST. Return success only after
persistence. Keep guest drafts separate from account caches and clear account
session data on logout. Run the Mi Garage and Compatibility tests after changes.
