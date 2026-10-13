/* global wp, uaipTimetableBlock */
// Editor UI for the Timetable and Upcoming Classes blocks. Both render on the server; the
// preview is the real output (ServerSideRender), made non-interactive in the editor.

// Wrapped in a function: these files ship unbundled, and the editor loads several of
// them on one page, so top-level names must not leak into the global scope.
(function () {
  const { registerBlockType } = wp.blocks;
  const { createElement: el, Fragment } = wp.element;
  const { __ } = wp.i18n;
  const { useBlockProps, InspectorControls } = wp.blockEditor;
  const { PanelBody, SelectControl, ToggleControl, CheckboxControl, RangeControl, TextControl, Placeholder, Button, Disabled, BaseControl } =
    wp.components;
  const ServerSideRender = wp.serverSideRender;

  const data = window.uaipTimetableBlock || {
    categories: [],
    locations: [],
    instructors: [],
    days: [],
    hasEvents: true,
    ics: true,
    defaults: {},
  };

  const VIEWS = [
    { value: "week", label: __("Week", "uaip-simple-timetable") },
    { value: "day", label: __("Day", "uaip-simple-timetable") },
    { value: "month", label: __("Month", "uaip-simple-timetable") },
    { value: "list", label: __("List", "uaip-simple-timetable") },
  ];
  const SCHEMES = [
    { value: "default", label: __("Default", "uaip-simple-timetable") },
    { value: "modern", label: __("Modern", "uaip-simple-timetable") },
    { value: "minimal", label: __("Minimal", "uaip-simple-timetable") },
    { value: "dark", label: __("Dark", "uaip-simple-timetable") },
    { value: "warm", label: __("Warm", "uaip-simple-timetable") },
  ];
  const SLOTS = [
    { value: "15", label: __("15 minutes", "uaip-simple-timetable") },
    { value: "30", label: __("30 minutes", "uaip-simple-timetable") },
    { value: "60", label: __("1 hour", "uaip-simple-timetable") },
  ];

  const labelOf = (list, value) => (list.find((o) => String(o.value) === String(value)) || {}).label || value;
  const siteDefault = (list, value) => ({
    value: "",
    label: value ? `${__("Site default", "uaip-simple-timetable")} (${labelOf(list, value)})` : __("Site default", "uaip-simple-timetable"),
  });

  /** A labelled list of checkboxes editing an array attribute. */
  function CheckList({ id, label, help, options, value, onChange }) {
    if (!options.length) return null;
    const selected = Array.isArray(value) ? value : [];
    return el(
      BaseControl,
      { id, label, help, __nextHasNoMarginBottom: true },
      el(
        "div",
        { id, className: "uaip-tt-checklist" },
        options.map((o) =>
          el(CheckboxControl, {
            key: o.value,
            label: o.label,
            checked: selected.includes(o.value),
            onChange: (on) => onChange(on ? [...selected, o.value] : selected.filter((v) => v !== o.value)),
            __nextHasNoMarginBottom: true,
          }),
        ),
      ),
    );
  }

  function NoEvents() {
    return el(
      Placeholder,
      {
        icon: "calendar-alt",
        label: __("Timetable", "uaip-simple-timetable"),
        instructions: __("There are no published events yet. Add your classes, then they appear here.", "uaip-simple-timetable"),
      },
      el(Button, { variant: "primary", href: data.newEvent, target: "_blank" }, __("Add your first event", "uaip-simple-timetable")),
    );
  }

  function Preview({ block, attributes }) {
    return el(Disabled, null, el(ServerSideRender, { block, attributes, skipBlockSupportAttributes: true }));
  }

  function FilterPanel({ attributes, setAttributes, title }) {
    return el(
      PanelBody,
      { title, initialOpen: false },
      el(CheckList, {
        id: "uaip-tt-b-cat",
        label: __("Categories", "uaip-simple-timetable"),
        options: data.categories,
        value: attributes.category,
        onChange: (category) => setAttributes({ category }),
      }),
      el(CheckList, {
        id: "uaip-tt-b-loc",
        label: __("Locations", "uaip-simple-timetable"),
        options: data.locations,
        value: attributes.location,
        onChange: (location) => setAttributes({ location }),
      }),
      el(CheckList, {
        id: "uaip-tt-b-ins",
        label: __("Instructors", "uaip-simple-timetable"),
        options: data.instructors,
        value: attributes.instructor,
        onChange: (instructor) => setAttributes({ instructor }),
      }),
      !data.categories.length &&
        !data.locations.length &&
        !data.instructors.length &&
        el("p", null, __("Add categories, locations or instructors to filter by them.", "uaip-simple-timetable")),
    );
  }

  registerBlockType("uaip-simple-timetable/timetable", {
    edit({ attributes, setAttributes }) {
      const blockProps = useBlockProps();
      const d = data.defaults || {};
      const view = attributes.view || d.view || "week";
      const views = Array.isArray(attributes.views) ? attributes.views : [];
      const toggleView = (v, on) => {
        let next = on ? [...views, v] : views.filter((x) => x !== v);
        // The starting view is always one of the views visitors can pick.
        if (next.length && !next.includes(view)) next = [view, ...next];
        setAttributes({ views: VIEWS.map((o) => o.value).filter((x) => next.includes(x)) });
      };
      const isGrid = view === "week" || view === "day" || views.includes("week") || views.includes("day");

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __("Display", "uaip-simple-timetable") },
            el(SelectControl, {
              label: __("Starting view", "uaip-simple-timetable"),
              value: attributes.view,
              options: [siteDefault(VIEWS, d.view)].concat(VIEWS),
              onChange: (v) => setAttributes({ view: v, views: views.length && v && !views.includes(v) ? [...views, v] : views }),
              __nextHasNoMarginBottom: true,
              __next40pxDefaultSize: true,
            }),
            el(
              BaseControl,
              {
                id: "uaip-tt-b-views",
                label: __("Visitors can switch to", "uaip-simple-timetable"),
                help: __("Tick two or more to show view buttons.", "uaip-simple-timetable"),
                __nextHasNoMarginBottom: true,
              },
              el(
                "div",
                { id: "uaip-tt-b-views", className: "uaip-tt-checklist uaip-tt-checklist--row" },
                VIEWS.map((o) =>
                  el(CheckboxControl, {
                    key: o.value,
                    label: o.label,
                    checked: views.includes(o.value),
                    disabled: attributes.typical && (o.value === "month" || o.value === "list"),
                    onChange: (on) => toggleView(o.value, on),
                    __nextHasNoMarginBottom: true,
                  }),
                ),
              ),
            ),
            el(SelectControl, {
              label: __("Colour scheme", "uaip-simple-timetable"),
              value: attributes.scheme,
              options: [siteDefault(SCHEMES, d.scheme)].concat(SCHEMES),
              onChange: (scheme) => setAttributes({ scheme }),
              __nextHasNoMarginBottom: true,
              __next40pxDefaultSize: true,
            }),
            el(ToggleControl, {
              label: __("Typical week", "uaip-simple-timetable"),
              help: __("Show the regular weekly schedule without dates or navigation.", "uaip-simple-timetable"),
              checked: !!attributes.typical,
              onChange: (typical) => setAttributes({ typical }),
              __nextHasNoMarginBottom: true,
            }),
            el(ToggleControl, {
              label: __("Filter dropdowns", "uaip-simple-timetable"),
              help: __("Let visitors filter by category, location or instructor.", "uaip-simple-timetable"),
              checked: attributes.filters !== false,
              onChange: (filters) => setAttributes({ filters }),
              __nextHasNoMarginBottom: true,
            }),
            data.ics &&
              el(ToggleControl, {
                label: __("Calendar subscription link", "uaip-simple-timetable"),
                checked: attributes.subscribe !== false,
                onChange: (subscribe) => setAttributes({ subscribe }),
                __nextHasNoMarginBottom: true,
              }),
          ),
          el(FilterPanel, { attributes, setAttributes, title: __("Show only", "uaip-simple-timetable") }),
          isGrid &&
            el(
              PanelBody,
              { title: __("Week and day grid", "uaip-simple-timetable"), initialOpen: false },
              el(CheckList, {
                id: "uaip-tt-b-days",
                label: __("Days shown in the week", "uaip-simple-timetable"),
                help: __("None ticked: all seven days.", "uaip-simple-timetable"),
                options: data.days,
                value: attributes.days,
                onChange: (days) => setAttributes({ days }),
              }),
              el(
                "div",
                { className: "uaip-tt-times" },
                el(TextControl, {
                  label: __("From", "uaip-simple-timetable"),
                  type: "time",
                  value: attributes.start,
                  onChange: (start) => setAttributes({ start }),
                  __nextHasNoMarginBottom: true,
                  __next40pxDefaultSize: true,
                }),
                el(TextControl, {
                  label: __("To", "uaip-simple-timetable"),
                  type: "time",
                  value: attributes.end,
                  onChange: (end) => setAttributes({ end }),
                  __nextHasNoMarginBottom: true,
                  __next40pxDefaultSize: true,
                }),
              ),
              el("p", { className: "components-base-control__help" }, __("Leave empty to fit the hours to the events.", "uaip-simple-timetable")),
              el(SelectControl, {
                label: __("Grid lines every", "uaip-simple-timetable"),
                value: attributes.slot ? String(attributes.slot) : "",
                options: [siteDefault(SLOTS, d.slot)].concat(SLOTS),
                onChange: (v) => setAttributes({ slot: Number(v) || 0 }),
                __nextHasNoMarginBottom: true,
                __next40pxDefaultSize: true,
              }),
            ),
          (view === "list" || views.includes("list")) &&
            el(
              PanelBody,
              { title: __("List", "uaip-simple-timetable"), initialOpen: false },
              el(RangeControl, {
                label: __("Days shown", "uaip-simple-timetable"),
                value: attributes.listDays || d.listDays || 14,
                min: 1,
                max: 90,
                onChange: (listDays) => setAttributes({ listDays: listDays || 0 }),
                __nextHasNoMarginBottom: true,
                __next40pxDefaultSize: true,
              }),
            ),
        ),
        el("div", blockProps, data.hasEvents ? el(Preview, { block: "uaip-simple-timetable/timetable", attributes }) : el(NoEvents)),
      );
    },
    save: () => null,
  });

  registerBlockType("uaip-simple-timetable/upcoming", {
    edit({ attributes, setAttributes }) {
      const blockProps = useBlockProps();
      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __("Settings", "uaip-simple-timetable") },
            el(RangeControl, {
              label: __("Number of classes", "uaip-simple-timetable"),
              value: attributes.count,
              min: 1,
              max: 20,
              onChange: (count) => setAttributes({ count: count || 5 }),
              __nextHasNoMarginBottom: true,
              __next40pxDefaultSize: true,
            }),
            el(RangeControl, {
              label: __("Look ahead (days)", "uaip-simple-timetable"),
              value: attributes.days,
              min: 1,
              max: 90,
              onChange: (days) => setAttributes({ days: days || 14 }),
              __nextHasNoMarginBottom: true,
              __next40pxDefaultSize: true,
            }),
            el(ToggleControl, {
              label: __("Show location", "uaip-simple-timetable"),
              checked: attributes.showLocation !== false,
              onChange: (showLocation) => setAttributes({ showLocation }),
              __nextHasNoMarginBottom: true,
            }),
            el(ToggleControl, {
              label: __("Show instructors", "uaip-simple-timetable"),
              checked: !!attributes.showInstructor,
              onChange: (showInstructor) => setAttributes({ showInstructor }),
              __nextHasNoMarginBottom: true,
            }),
          ),
          el(FilterPanel, { attributes, setAttributes, title: __("Show only", "uaip-simple-timetable") }),
        ),
        el("div", blockProps, data.hasEvents ? el(Preview, { block: "uaip-simple-timetable/upcoming", attributes }) : el(NoEvents)),
      );
    },
    save: () => null,
  });
})();
