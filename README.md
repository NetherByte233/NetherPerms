# NetherPerms

NetherPerms is a modern permissions plugin for PocketMine-MP. It lets server admins control what features players can use by creating groups and assigning permissions.

It is:
- **Fast** — written with performance and scalability in mind.
- **Easy to use** — set up permissions using commands, directly in config files, or using the in-game editor.
- **Extensive** — a rich set of customization options and settings to suit your server.
- **Free** — available at no cost, permissively licensed so it can remain free forever.

### Storage Backends

NetherPerms can store data in two ways: "SQLite" and "YAML". You can change this in the `storage` setting of `config.yml`.

The default option is **SQLite**.

| Backend | Pros | Cons |
| --- | --- | --- |
| SQLite (single DB file) | Fast and robust; WAL journaling; fewer files to manage; good durability | Not as friendly for hand-editing; requires SQLite3 extension (bundled with PMMP builds) |
| YAML (per-entity files) | Human-readable; easy to edit; simple to version with Git | Many small files; slower I/O on some hosts; filesystem-bound concurrency |

### Commands

A full list with examples and permissions is in `COMMAND_USAGE.md`.

Highlights:
- Groups: create/delete/rename/clone, permissions, parents, weights, meta, list members, show tracks.
- Users: show info, parents (add/set/remove/switchprimarygroup), permissions, meta, primary group (show/set/unset), temporary permissions.
- Tracks:
  - Legacy syntax: `/np track set <track> <g1> <g2> ...` and `/np track show <track>`.
  - Preferred syntax: `/np track <track> info|set|rename|clone|insert|remove|append ...`.
  - Promote/demote along a track: `/np promote <player> <track>`, `/np demote <player> <track>`.

### Contexts

A context is a set of conditions where a permission or parent applies:
- Supported keys: `world`, `gamemode`.
- Format: `key=value` pairs separated by commas, e.g. `world=world,gamemode=survival`.

If no context is provided, the change applies globally.

### Wildcards and Temporary Permissions

- Wildcards: Nodes ending with `.*` expand to all registered permissions with that prefix (PMMP registry), without overriding explicitly set nodes.
- Temporary permissions: Grant user permissions that expire after a duration (e.g., `10m`, `1h30m`, `2d3h`).

### Installation

1. Place the plugin into your `plugins/` directory.
2. Configure groups, users, and tracks via commands.

### Troubleshooting

- Group deletion doesn’t remove the YAML file:
  - Ensure you run a command that saves data (most management commands do), or restart/reload; the per-entity saver removes stale files.
- Promote/Demote says “Track not found”:
  - Verify the track exists and contains at least two ordered groups.
- Permission isn’t applying:
  - Check priority and meta, and whether contexts limit the scope (world/gamemode).

### License

[MIT](LICENSE)