# NetherPerms

NetherPerms is a modern permissions plugin for PocketMine-MP with:
- Per-entity YAML storage (one file per group/track/user).
- Backwards-compatible reading of legacy `users.yml`/[groups.yml](cci:7://file:///c:/Users/Aankit_Dobriyal/Desktop/minecraft/Perms/NetherPerms/resources/groups.yml:0:0-0:0) (read-only).
- Auto-creation of a `default` group if none exist.
- Primary group support, group weights, and display meta (prefix/suffix).
- Track management with promote/demote commands.
- Context-aware permissions (world, gamemode).
- Wildcard permissions expansion (e.g., `some.perm.*`).
- Temporary permissions with expiration.

## Storage Layout

New per-entity storage under the plugin resources directory:
- `groups/` — one `groupName.yml` per group.
- `tracks/` — one `trackName.yml` per track with `groups: [g1, g2, ...]`.
- `users/` — one `uuid.yml` per user, only created if the user has:
  - Non-empty permissions, temporary permissions, meta, groups, or a primary group.

On startup:
- If the new directories contain YAML files, they are used.
- If they are empty/missing but legacy `users.yml` or [groups.yml](cci:7://file:///c:/Users/Aankit_Dobriyal/Desktop/minecraft/Perms/NetherPerms/resources/groups.yml:0:0-0:0) exist, they are read for migration purposes (no rewrite to legacy).
- If no groups are found, `groups/default.yml` is created automatically.

Save behavior:
- Only the new directories are written (`groups/`, `tracks/`, `users/`).
- Legacy aggregate files are not created or overwritten.
- When saving, stale files are deleted:
  - `groups/<name>.yml` is removed if the group no longer exists.
  - `tracks/<name>.yml` is removed if the track no longer exists.

Deletion behavior:
- Deleting a group:
  - Removes it from all users’ group lists.
  - Unsets any user’s `primary` group that pointed to the deleted group.
  - Removes it from every other group’s `parents` list.
  - Removes it from every track it appears in.
  - Persists deletion to `groups/<group>.yml` (file is removed).

## Commands

A full list with examples and permissions is in [COMMAND_USAGE.md](COMMAND_USAGE.md).

Highlights:
- Groups: create/delete/rename/clone, permissions, parents, weights, meta, list members, show tracks.
- Users: show info, parents (add/set/remove/switchprimarygroup), permissions, meta, primary group (show/set/unset), temporary permissions.
- Tracks:
  - Legacy syntax: `/np track set <track> <g1> <g2> ...` and `/np track show <track>`.
  - Preferred syntax: `/np track <track> info|set|rename|clone|insert|remove|append ...`.
  - Promote/demote along a track: `/np promote <player> <track>`, `/np demote <player> <track>`.

## Contexts

A context is a set of conditions where a permission or parent applies:
- Supported keys: `world`, `gamemode`.
- Format: `key=value` pairs separated by commas, e.g. `world=world,gamemode=survival`.

If no context is provided, the change applies globally.

## Wildcards and Temporary Permissions

- Wildcards: Nodes ending with `.*` will expand to all registered permissions with that prefix (PMMP registry), without overriding explicitly set nodes.
- Temporary permissions: You can grant user permissions that expire after a duration (e.g., `10m`, `1h30m`, `2d3h`, etc.).

## Installation

1. Place the plugin into your `plugins/` directory.
2. Configure groups, users, and tracks via commands.

## Troubleshooting

- Group deletion doesn’t remove the YAML file:
  - Ensure you run a command that saves data (most management commands do), or restart/reload; the per-entity saver removes stale files.
- Promote/Demote says “Track not found”:
  - Verify the track exists and contains at least two ordered groups.
- Permission isn’t applying:
  - Check priority and meta, and whether contexts limit the scope (world/gamemode).

## License

[MIT](LICENSE)