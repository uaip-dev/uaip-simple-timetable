=== UAIP Simple Timetable ===
Contributors: uaip
Tags: timetable, schedule, class schedule, calendar, events
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Class schedules and timetables with week, day, month and list views, repeating events, locations and instructors. Free, no premium version.

== Description ==

UAIP Simple Timetable shows your classes, lessons or sessions as a clear timetable. Gyms, studios, schools, clubs and community centres can set up a weekly schedule in minutes. Every feature is free: there is no premium version, no locked settings and no upgrade notices.

= Events that repeat =

* Weekly events on one or more days, every week or every 2–8 weeks.
* Optional end date, and cancelled dates for holidays or closures.
* One-off events (workshops, open days) in the same timetable.
* Times follow your site's timezone, time format and first day of the week.

= Four views =

* **Week:** a grid with one column per day. Long and overlapping events are placed correctly, side by side.
* **Day:** one day at a time.
* **Month:** a calendar with every event.
* **List:** upcoming events grouped by day.

Visitors can move between weeks, months or days and switch views without reloading the page. On phones, the week and month views turn into a list per day.

= Locations, instructors and categories =

* Give each event a location and one or more instructors.
* Categories (Yoga, HIIT, Maths…) each have a colour; events can also set their own.
* Visitors can filter the timetable by category, location or instructor.
* Clicking an event opens its details: time, place, instructors, description and a link to more information or a booking page.
* Each event has its own page with its schedule and next dates. Location and instructor pages are optional.

= Built for every site =

* Works without JavaScript; the script only adds in-place navigation and the details popup.
* Keyboard and screen-reader friendly, with automatic text colours that keep event labels readable.
* Five colour schemes: default, modern, minimal, dark and warm.
* No external services, no tracking, no cookies.

= Shortcode =

Add a timetable anywhere with `[uaip_timetable]`. Options:

* `view`: `week` (default), `day`, `month` or `list`.
* `views`: views visitors can switch between, e.g. `views="week,month,list"`.
* `category`: category slugs, e.g. `category="yoga,pilates"`.
* `location` / `instructor`: IDs.
* `days`: days shown in the week view, e.g. `days="mon,tue,wed,thu,fri"`.
* `start` / `end`: visible hours, e.g. `start="08:00" end="20:00"` (default: fitted to the events).
* `slot`: grid lines every `15`, `30` or `60` minutes.
* `typical`: `yes` shows a "typical week" without dates or navigation.
* `filters`: `no` hides the filter dropdowns.
* `list_days`: how many days the list view shows (default 14).
* `scheme`: `default`, `modern`, `minimal`, `dark` or `warm`.
* `date`: the date to start from (YYYY-MM-DD), default today.

Example: `[uaip_timetable views="week,list" category="yoga" days="mon,tue,wed,thu,fri"]`

= Source code =

The plugin's full source, including the unminified JavaScript and CSS and the build scripts, is at [github.com/uaip-dev/uaip-simple-timetable](https://github.com/uaip-dev/uaip-simple-timetable).

== Installation ==

1. Install and activate the plugin from Plugins → Add New.
2. Go to Timetable → Categories and add a few categories with colours (optional).
3. Add locations and instructors under Timetable (optional).
4. Go to Timetable → Add Event. Set the first date, start and end time, and the days it repeats on.
5. Add `[uaip_timetable]` to any page.

== Frequently Asked Questions ==

= How do I make a class that runs every Monday and Wednesday? =

Create one event, set its first date, tick Monday and Wednesday under "On", and leave "Repeat until" empty.

= A class is cancelled on a public holiday. What do I do? =

Open the event and add the date under "Cancelled dates". It disappears from the timetable for that day only.

= Can I show only some classes? =

Yes. Use `category`, `location` or `instructor` in the shortcode, for example `[uaip_timetable category="yoga"]`. Visitors can't change filters you set this way.

= Does the plugin send data anywhere? =

No. It makes no requests to other servers and stores nothing about your visitors.

= Is there a premium version? =

No. Everything is free.

== Changelog ==

= 0.1.0 =
* First version: repeating and one-off events, locations, instructors, categories with colours, week/day/month/list views, filters, event details popup, shortcode.
