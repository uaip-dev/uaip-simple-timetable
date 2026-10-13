# UAIP Simple Timetable

A free WordPress plugin for class schedules and timetables: repeating and one-off events,
locations, instructors and colour-coded categories, shown as a week grid, day, month or
list. Everything is free; there is no premium version.

Maintained by [UAIP](https://uaip.dev/) (WordPress.org: `uaip`).

## Repository layout

| Folder | What |
|---|---|
| `plugin/` | The WordPress plugin (PHP). `plugin/build/` is generated. |
| `src/front/` | Front-end script (TypeScript Script Module) and styles. |
| `src/editor/` | Block editor "Schedule" panel for events (uses the `wp.*` globals). |
| `src/admin/` | Classic admin helpers (colour pickers, meta box). |
| `tests/` | Occurrence engine tests (plain PHP). |
| `dev/` | Docker setup for a local WordPress test site with sample data. |
| `scripts/` | Build and packaging scripts. |

## Build

Requirements: Node 22.13+ and pnpm.

```sh
pnpm install
pnpm build        # src/ → plugin/build/
pnpm watch        # rebuild on change, with source maps
pnpm check        # type-check the front-end script
pnpm zip          # build + dist/uaip-simple-timetable.zip
```

## Local WordPress

```sh
cd dev
docker compose up -d
sh setup.sh       # installs WordPress, activates the plugin, adds a sample gym timetable
```

The site runs at http://localhost:8096 (user `admin`, password in `dev/.data/admin-password.txt`).
Sample pages: `/timetable/`, `/class-list/`, `/typical-week/`, `/yoga/`.

Run the engine tests in the container:

```sh
docker compose -f dev/docker-compose.yml exec wordpress php /uaip-tests/occurrences-test.php
```

## How it works

- Events (`uaip_tt_event`) store a first date, start and end time, and an optional weekly
  repeat (days, every N weeks, until, cancelled dates) as post meta.
- `Uaip_Timetable_Occurrences` expands them into dated occurrences in the site timezone.
  All views, the REST API and single pages use it. Weekly repeats count weeks from Monday,
  as iCalendar does (`WKST=MO`).
- `Uaip_Timetable_Renderer` renders the views as plain HTML that works without JavaScript.
  The front-end script fetches re-rendered HTML from `GET /wp-json/uaip-simple-timetable/v1/render`
  for navigation and filters.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
