# NetherPerms Command Usage

This document lists all NetherPerms commands, their usage, required permissions, and important notes.

Unless stated otherwise, commands can be run from console or in-game. By default, OPs and the console have access to all documented commands (see `plugin.yml`).

Legend:
- <> = required argument
- [] = optional argument
- (ctx) = optional contexts in `key=value` pairs, comma-separated. Example: `world=world_nether,gamemode=survival`.

---

## General

- Editor (UI; in-game only)
  - Command: `/np editor`
  - Permission: `netherperms.ui`

- Reload
  - Command: `/np reload`
  - Permission: [netherperms.reload](cci:1://file:///c:/Users/Aankit_Dobriyal/Desktop/minecraft/Perms/NetherPerms/src/NetherByte/NetherPerms/storage/YamlStorage.php:43:4-100:5)

- Info
  - Command: `/np info [player]`
  - Permissions: `netherperms.info` (and related if inspecting another player)

---

## Groups

- Create Group
  - Command: `/np creategroup <group> [weight] [displayname]`
  - Permission: `netherperms.creategroup`

- Delete Group
  - Command: `/np deletegroup <group>`
  - Permission: `netherperms.deletegroup`
  - Notes: Removes the group from users’ memberships and primary where applicable, removes from other groups’ parents and from all tracks, and deletes `groups/<group>.yml`.

- List Groups
  - Command: `/np listgroups`
  - Permission: `netherperms.group.list`

- Group Info
  - Command: `/np group <group> info`
  - Permission: `netherperms.group.info`

- Group Permissions
  - Set: `/np group <group> permission set <node> [true|false] [ctx]`
    - Permission: `netherperms.group.permission.set`
    - Default value is `true` if omitted.
  - Unset: `/np group <group> permission unset <node> [ctx]`
    - Permission: `netherperms.group.permission.unset`
  - Base node: `netherperms.group.permission`

- Group Parents
  - Add: `/np group <group> parent add <parent> [ctx]`
    - Permission: `netherperms.group.parent.add`
  - Set (replace): `/np group <group> parent set <parent> [ctx]`
    - Permission: `netherperms.group.parent.set`
  - Remove: `/np group <group> parent remove <parent> [ctx]`
    - Permission: `netherperms.group.parent.remove`
  - List: `/np group <group> parent list [ctx]`
    - Permission: `netherperms.group.parent.list`
  - Base node: `netherperms.group.parent`

- Group Weight
  - Command: `/np group <group> setweight <int>`
  - Permission: `netherperms.group.setweight`

- Group Display Name
  - Command: `/np group <group> setdisplayname <name>`
  - Permission: `netherperms.group.setdisplayname`

- Group Meta (prefix/suffix)
  - Set: `/np group <group> meta set <prefix|suffix> <value>`
    - Permission: `netherperms.group.meta.set`
  - Unset: `/np group <group> meta unset <prefix|suffix>`
    - Permission: `netherperms.group.meta.unset`
  - Base node: `netherperms.group.meta`

- Group Members
  - Command: `/np group <group> listmembers [page]`
  - Permission: `netherperms.group.listmembers`

- Show Tracks for a Group
  - Command: `/np group <group> showtracks`
  - Permission: `netherperms.group.showtracks`

- Rename Group
  - Command: `/np group <group> rename <newName>`
  - Permission: `netherperms.group.rename`

- Clone Group
  - Command: `/np group <group> clone <cloneName>`
  - Permission: `netherperms.group.clone`

---

## Users

- Info
  - Command: `/np user <user> info`
  - Permission: `netherperms.user.info`

- Parents
  - Add: `/np user <user> parent add <group> [ctx]`
    - Permission: `netherperms.user.parent.add`
  - Set (replace all; no context): `/np user <user> parent set <group>`
    - Permission: `netherperms.user.parent.set`
    - Notes: Clears existing parents, sets the new group, and updates the stored primary.
  - Remove: `/np user <user> parent remove <group> [ctx]`
    - Permission: `netherperms.user.parent.remove`
  - Switch stored primary group (ensure membership, do not clear others):
    - Command: `/np user <user> parent switchprimarygroup <group>`
    - Permission: `netherperms.user.parent.switchprimarygroup`
  - Base node: `netherperms.user.parent`

- Permissions
  - Set: `/np user <user> permission set <node> [true|false] [ctx]`
    - Permission: `netherperms.user.permission.set`
  - Unset: `/np user <user> permission unset <node> [ctx]`
    - Permission: `netherperms.user.permission.unset`
  - Temporary: `/np user <user> permission settemp <node> [true|false] <duration> [ctx]`
    - Permission: `netherperms.user.permission.settemp`
    - Duration examples: `60`, `10m`, `1h30m`, `2d3h`.
  - Unset Temporary: `/np user <user> permission unsettemp <node> [ctx]`
    - Permission: `netherperms.user.permission.unsettemp`
  - Base node: `netherperms.user.permission`

- Meta (prefix/suffix)
  - Set: `/np user <user> meta set <prefix|suffix> <value> [ctx]`
    - Permission: `netherperms.user.meta.set`
  - Unset: `/np user <user> meta unset <prefix|suffix> [ctx]`
    - Permission: `netherperms.user.meta.unset`
  - Base node: `netherperms.user.meta`

- Primary Group
  - Show: `/np user <user> primary show`
    - Permission: `netherperms.user.primary.show`
  - Set: `/np user <user> primary set <group>`
    - Permission: `netherperms.user.primary.set`
  - Unset: `/np user <user> primary unset`
    - Permission: `netherperms.user.primary.unset`
  - Base node: `netherperms.user.primary`

---

## Tracks

- Create Track
  - Command: `/np createtrack <track>`
  - Permission: `netherperms.track.create`

- Delete Track
  - Command: `/np deletetrack <track>`
  - Permission: `netherperms.track.delete`

- List Tracks
  - Command: `/np listtracks`
  - Permission: `netherperms.track.list`

- Set Track Order
  - Legacy: `/np track set <track> <g1> <g2> [g3 ...]`
  - Preferred: `/np track <track> set <g1> <g2> [g3 ...]`
  - Permission: `netherperms.track.set`
  - Notes: Defines groups from lowest to highest. Unknown groups will be reported.

- Show Track
  - Legacy: `/np track show <track>`
  - Preferred: `/np track <track> info`
  - Permission: `netherperms.track.show`

- Track Maintenance (preferred syntax)
  - Rename: `/np track <track> rename <newName>`
    - Permission: `netherperms.track.rename`
  - Clone: `/np track <track> clone <cloneName>`
    - Permission: `netherperms.track.clone`
  - Insert group at position (1-based): `/np track <track> insert <group> <position>`
    - Permission: `netherperms.track.insert`
  - Remove group: `/np track <track> remove <group>`
    - Permission: `netherperms.track.remove`
  - Append group: `/np track <track> append <group>`
    - Permission: `netherperms.track.append`

- Promote/Demote on Track
  - Promote: `/np promote <player> <track>`
    - Permission: [netherperms.promote](cci:1://file:///c:/Users/Aankit_Dobriyal/Desktop/minecraft/Perms/NetherPerms/src/NetherByte/NetherPerms/permission/PermissionManager.php:930:4-953:5)
    - Notes: Adds the next group on the track and sets it as the stored primary.
  - Demote: `/np demote <player> <track>`
    - Permission: [netherperms.demote](cci:1://file:///c:/Users/Aankit_Dobriyal/Desktop/minecraft/Perms/NetherPerms/src/NetherByte/NetherPerms/permission/PermissionManager.php:955:4-977:5)
    - Notes: Moves down the track, sets the new group as stored primary.

---

## Contexts

- Supported keys: `world`, `gamemode`, `dimension`.
- Format examples:
  - Single: `world=world`
  - Multiple: `world=world,gamemode=survival`
- If no context is provided, operations apply globally.

---

## Notes

- Deleting a group fully purges it from users, other groups’ parents, and all tracks, and deletes its YAML file on save.
- Per-entity storage never recreates legacy aggregate files.
- Wildcards (`node.*`) expand to all registered permission nodes with that prefix, without overriding explicit nodes.