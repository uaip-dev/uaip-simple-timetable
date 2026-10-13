/* global wp, uaipTimetableEditor */
// "Schedule" panel in the block editor sidebar for timetable events. Edits the event's
// REST-registered meta; the classic editor uses the PHP meta box instead.

// Wrapped in a function: these files ship unbundled, and the editor loads several of
// them on one page, so top-level names must not leak into the global scope.
(function () {
  const { registerPlugin } = wp.plugins;
  const PluginDocumentSettingPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
  const { useSelect, useDispatch } = wp.data;
  const { createElement: el, Fragment, useState, useEffect } = wp.element;
  const { __, _n, sprintf } = wp.i18n;
  const { TextControl, SelectControl, CheckboxControl, RadioControl, Notice, Button, BaseControl, ColorPalette, ExternalLink } = wp.components;

  const data = window.uaipTimetableEditor || { locations: [], instructors: [], days: [], maxInterval: 8, today: "" };
  const POST_TYPE = "uaip_tt_event";
  const PANEL_ID = "uaip-tt-schedule/uaip-tt-schedule";
  const K = {
    date: "_uaip_tt_date",
    start: "_uaip_tt_start",
    end: "_uaip_tt_end",
    repeat: "_uaip_tt_repeat",
    interval: "_uaip_tt_interval",
    days: "_uaip_tt_days",
    until: "_uaip_tt_until",
    exdates: "_uaip_tt_exdates",
    location: "_uaip_tt_location",
    instructors: "_uaip_tt_instructors",
    color: "_uaip_tt_color",
    url: "_uaip_tt_url",
  };
  const COLORS = [
    { name: __("Blue", "uaip-simple-timetable"), color: "#2271b1" },
    { name: __("Teal", "uaip-simple-timetable"), color: "#00a0a0" },
    { name: __("Green", "uaip-simple-timetable"), color: "#2e8540" },
    { name: __("Orange", "uaip-simple-timetable"), color: "#d9641c" },
    { name: __("Red", "uaip-simple-timetable"), color: "#c9302c" },
    { name: __("Purple", "uaip-simple-timetable"), color: "#7b4fbf" },
    { name: __("Pink", "uaip-simple-timetable"), color: "#c2367a" },
    { name: __("Grey", "uaip-simple-timetable"), color: "#50575e" },
  ];

  /** Same checks as Uaip_Timetable_Event::rule_problem() in PHP. */
  function problem(m) {
    if (!m[K.date]) return __("Set a date.", "uaip-simple-timetable");
    if (!m[K.start] || !m[K.end]) return __("Set a start and end time.", "uaip-simple-timetable");
    if (m[K.end] <= m[K.start]) return __("The end time must be after the start time.", "uaip-simple-timetable");
    if (m[K.repeat] !== "none" && m[K.until] && m[K.until] < m[K.date]) {
      return __('The "repeat until" date is before the first date.', "uaip-simple-timetable");
    }
    return "";
  }

  /** Weekday (0 = Sunday) of a YYYY-MM-DD date. */
  function weekdayOf(date) {
    const d = new Date(date + "T12:00:00Z");
    return Number.isNaN(d.getTime()) ? -1 : d.getUTCDay();
  }

  function CancelledDates({ value, onChange }) {
    const [draft, setDraft] = useState("");
    const add = () => {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(draft) || value.includes(draft)) return;
      onChange([...value, draft].sort());
      setDraft("");
    };
    return el(
      BaseControl,
      {
        id: "uaip-tt-exdate",
        label: __("Cancelled dates", "uaip-simple-timetable"),
        help: __("Dates when this event does not take place (holidays, closures).", "uaip-simple-timetable"),
        __nextHasNoMarginBottom: true,
      },
      value.length > 0 &&
        el(
          "ul",
          { className: "uaip-tt-exdates" },
          value.map((date) =>
            el(
              "li",
              { key: date },
              el("span", null, date),
              el(Button, {
                variant: "link",
                isDestructive: true,
                label: sprintf(
                  /* translators: %s: date */
                  __("Remove %s", "uaip-simple-timetable"),
                  date,
                ),
                onClick: () => onChange(value.filter((d) => d !== date)),
                children: __("Remove", "uaip-simple-timetable"),
              }),
            ),
          ),
        ),
      el(
        "div",
        { className: "uaip-tt-exdate-add" },
        el("input", {
          id: "uaip-tt-exdate",
          type: "date",
          value: draft,
          onChange: (e) => setDraft(e.target.value),
          onKeyDown: (e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              add();
            }
          },
        }),
        el(Button, { variant: "secondary", onClick: add, disabled: !draft }, __("Add", "uaip-simple-timetable")),
      ),
    );
  }

  function SchedulePanel() {
    const { postType, meta, isNew } = useSelect((select) => {
      const editor = select("core/editor");
      return {
        postType: editor.getCurrentPostType(),
        meta: editor.getEditedPostAttribute("meta") || {},
        isNew: editor.isEditedPostNew(),
      };
    }, []);
    const { editPost } = useDispatch("core/editor");
    const editorDispatch = useDispatch("core/editor");
    const panelOpen = useSelect((select) => {
      const editor = select("core/editor");
      return editor.isEditorPanelOpened ? editor.isEditorPanelOpened(PANEL_ID) : true;
    }, []);
    const set = (key, value) => editPost({ meta: { [key]: value } });

    // Document panels remember their open state; open ours when the schedule still needs
    // filling in (always the case for a new event).
    const needsAttention = postType === POST_TYPE && (isNew || !!problem(meta));
    useEffect(() => {
      if (needsAttention && !panelOpen && editorDispatch.toggleEditorPanelOpened) editorDispatch.toggleEditorPanelOpened(PANEL_ID);
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [needsAttention]);

    // New events start today.
    useEffect(() => {
      if (postType === POST_TYPE && isNew && !meta[K.date] && data.today) set(K.date, data.today);
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [postType, isNew]);

    if (postType !== POST_TYPE || !PluginDocumentSettingPanel) return null;

    const repeat = meta[K.repeat] === "none" ? "none" : "weekly";
    const days = Array.isArray(meta[K.days]) ? meta[K.days] : [];
    const instructors = Array.isArray(meta[K.instructors]) ? meta[K.instructors] : [];
    const exdates = Array.isArray(meta[K.exdates]) ? meta[K.exdates] : [];
    const issue = problem(meta);
    const firstDay = weekdayOf(meta[K.date] || "");
    const interval = Number(meta[K.interval]) || 1;

    const toggleDay = (day, on) => set(K.days, on ? [...new Set([...days, day])].sort() : days.filter((d) => d !== day));
    const toggleInstructor = (id, on) =>
      set(K.instructors, on ? [...new Set([...instructors, id])] : instructors.filter((i) => i !== id));

    return el(
      PluginDocumentSettingPanel,
      { name: "uaip-tt-schedule", title: __("Schedule", "uaip-simple-timetable"), className: "uaip-tt-panel" },
      issue && el(Notice, { status: "warning", isDismissible: false }, issue, " ", __("The event is not shown in timetables until this is fixed.", "uaip-simple-timetable")),
      el(TextControl, {
        label: repeat === "none" ? __("Date", "uaip-simple-timetable") : __("First date", "uaip-simple-timetable"),
        type: "date",
        value: meta[K.date] || "",
        onChange: (v) => set(K.date, v),
        __nextHasNoMarginBottom: true,
        __next40pxDefaultSize: true,
      }),
      el(
        "div",
        { className: "uaip-tt-times" },
        el(TextControl, {
          label: __("Start", "uaip-simple-timetable"),
          type: "time",
          value: meta[K.start] || "",
          onChange: (v) => set(K.start, v),
          __nextHasNoMarginBottom: true,
          __next40pxDefaultSize: true,
        }),
        el(TextControl, {
          label: __("End", "uaip-simple-timetable"),
          type: "time",
          value: meta[K.end] || "",
          onChange: (v) => set(K.end, v),
          __nextHasNoMarginBottom: true,
          __next40pxDefaultSize: true,
        }),
      ),
      el(RadioControl, {
        label: __("Repeat", "uaip-simple-timetable"),
        selected: repeat,
        options: [
          { label: __("Every week", "uaip-simple-timetable"), value: "weekly" },
          { label: __("Once", "uaip-simple-timetable"), value: "none" },
        ],
        onChange: (v) => set(K.repeat, v),
      }),
      repeat === "weekly" &&
        el(
          Fragment,
          null,
          el(
            BaseControl,
            {
              id: "uaip-tt-days",
              label: __("On", "uaip-simple-timetable"),
              help:
                days.length === 0 && firstDay >= 0
                  ? sprintf(
                      /* translators: %s: weekday name */
                      __("No day ticked: repeats on %s, the weekday of the first date.", "uaip-simple-timetable"),
                      (data.days.find((d) => d.value === firstDay) || {}).label || "",
                    )
                  : undefined,
              __nextHasNoMarginBottom: true,
            },
            el(
              "div",
              { className: "uaip-tt-days", id: "uaip-tt-days" },
              data.days.map((day) =>
                el(CheckboxControl, {
                  key: day.value,
                  label: day.label,
                  checked: days.includes(day.value),
                  onChange: (on) => toggleDay(day.value, on),
                  __nextHasNoMarginBottom: true,
                }),
              ),
            ),
          ),
          el(SelectControl, {
            label: __("Frequency", "uaip-simple-timetable"),
            value: String(interval),
            options: Array.from({ length: data.maxInterval }, (_, i) => ({
              value: String(i + 1),
              label:
                i === 0
                  ? __("Every week", "uaip-simple-timetable")
                  : sprintf(
                      /* translators: %d: number of weeks */
                      _n("Every %d week", "Every %d weeks", i + 1, "uaip-simple-timetable"),
                      i + 1,
                    ),
            })),
            onChange: (v) => set(K.interval, Number(v)),
            __nextHasNoMarginBottom: true,
            __next40pxDefaultSize: true,
          }),
          el(TextControl, {
            label: __("Repeat until (optional)", "uaip-simple-timetable"),
            type: "date",
            value: meta[K.until] || "",
            min: meta[K.date] || undefined,
            onChange: (v) => set(K.until, v),
            __nextHasNoMarginBottom: true,
            __next40pxDefaultSize: true,
          }),
          el(CancelledDates, { value: exdates, onChange: (v) => set(K.exdates, v) }),
        ),
      el(SelectControl, {
        label: __("Location", "uaip-simple-timetable"),
        value: String(meta[K.location] || 0),
        options: [{ value: "0", label: __("— None —", "uaip-simple-timetable") }].concat(
          data.locations.map((o) => ({ value: String(o.value), label: o.label })),
        ),
        onChange: (v) => set(K.location, Number(v)),
        help: el(ExternalLink, { href: data.newLocation }, __("Add a location", "uaip-simple-timetable")),
        __nextHasNoMarginBottom: true,
        __next40pxDefaultSize: true,
      }),
      el(
        BaseControl,
        {
          id: "uaip-tt-instructors",
          label: __("Instructors", "uaip-simple-timetable"),
          help: el(ExternalLink, { href: data.newInstructor }, __("Add an instructor", "uaip-simple-timetable")),
          __nextHasNoMarginBottom: true,
        },
        el(
          "div",
          { id: "uaip-tt-instructors", className: "uaip-tt-checklist" },
          data.instructors.length === 0
            ? el("p", null, __("No instructors yet.", "uaip-simple-timetable"))
            : data.instructors.map((o) =>
                el(CheckboxControl, {
                  key: o.value,
                  label: o.label,
                  checked: instructors.includes(o.value),
                  onChange: (on) => toggleInstructor(o.value, on),
                  __nextHasNoMarginBottom: true,
                }),
              ),
        ),
      ),
      el(
        BaseControl,
        {
          id: "uaip-tt-color",
          label: __("Colour", "uaip-simple-timetable"),
          help: __("Leave empty to use the category colour.", "uaip-simple-timetable"),
          __nextHasNoMarginBottom: true,
        },
        el(ColorPalette, {
          colors: COLORS,
          value: meta[K.color] || undefined,
          onChange: (v) => set(K.color, v || ""),
          disableCustomColors: false,
          clearable: true,
        }),
      ),
      el(TextControl, {
        label: __("Link (optional)", "uaip-simple-timetable"),
        type: "url",
        value: meta[K.url] || "",
        placeholder: "https://",
        help: __('Where "More details" goes, e.g. a booking page. Leave empty to use this event\'s own page.', "uaip-simple-timetable"),
        onChange: (v) => set(K.url, v),
        __nextHasNoMarginBottom: true,
        __next40pxDefaultSize: true,
      }),
    );
  }

  registerPlugin("uaip-tt-schedule", { render: SchedulePanel, icon: "calendar-alt" });
})();
